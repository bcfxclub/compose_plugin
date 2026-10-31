// Cached async config getter for common use across the module.
// Use `getConfig().then(cfg => { ... })` to access configuration.
var _configCache = null;
var _configPromise = null;
var caURL = "/plugins/compose.manager/include/Exec.php";

// Shared HTML/attribute escape helpers (namespaced to avoid global collisions with other plugins)
function composeEscapeHtml(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

function composeEscapeAttr(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}


// Delay used when positioning the file tree picker. This gives the picker a moment
// to be inserted/rendered by the underlying fileTreeAttach logic before we compute
// and apply its size/position.
var FILE_TREE_POSITION_DELAY_MS = 25;

// Shared tracking state for file-tree popups shown next to path inputs.
// We use a single requestAnimationFrame loop so multiple open pickers don't each create their own interval timer.
var composeFileTreeTrackers = [];
var composeFileTreeTrackRafId = null;

function composeFileTreeRunTracks() {
    if (composeFileTreeTrackers.length === 0) {
        composeFileTreeTrackRafId = null;
        return;
    }

    composeFileTreeTrackers = composeFileTreeTrackers.filter(function (tracker) {
        var $input = tracker.$input;
        var $picker = $input.next('.fileTree');

        if (!$picker.length || !$picker.is(':visible')) {
            tracker.cleanup();
            return false;
        }

        tracker.update();
        return true;
    });

    composeFileTreeTrackRafId = window.requestAnimationFrame(composeFileTreeRunTracks);
}

function composeFileTreeRemoveTracker($input) {
    var tracker = $input.data('composeFileTreeTrackId');
    if (!tracker) return;

    tracker.cleanup();
    composeFileTreeTrackers = composeFileTreeTrackers.filter(function (t) { return t !== tracker; });
    $input.removeData('composeFileTreeTrackId');
    $input.removeData('composeFileTreeTrackHandlers');
}

function getConfig() {
    if (_configCache) return Promise.resolve(_configCache);
    if (_configPromise) return _configPromise;

    _configPromise = new Promise(function (resolve, reject) {
        $.post(caURL, { action: 'getConfig' }, function (response) {
            var resp = response;
            if (typeof resp === 'string') {
                try {
                    resp = JSON.parse(resp);
                } catch (e) {
                    _configPromise = null;
                    composeLogger('getConfig 返回了非 JSON 响应；改用空配置', { response: response, error: e }, 'user', 'error', 'config');
                    resolve({});
                    return;
                }
            }

            if (resp && resp.result === 'success') {
                _configCache = resp.config;
                _configPromise = null;
                resolve(_configCache);
            } else {
                _configPromise = null;
                composeLogger('getConfig 返回了非成功响应；改用空配置', resp, 'user', 'error', 'config');
                resolve({});
            }
        }).fail(function () {
            _configPromise = null;
            composeLogger('获取配置时发生网络错误；改用空配置', null, 'user', 'error', 'config');
            resolve({});
        });
    });

    return _configPromise;
}

// Client-side debug helper: logs to console and posts short messages to server syslog
function composeLogger(msg, obj, type, lvl, category) {
    // Send lightweight debug message to server for persistence (non-blocking)
    try {
        var payload = {
            action: 'composeLogger',
            msg: msg,
            type: type || 'user',
            lvl: lvl || 'info'
        };
        if (category !== undefined && category !== null && category !== '') payload.category = String(category);
        if (obj !== undefined) payload.data = JSON.stringify(obj);
        // Fire-and-forget; no UI impact
        $.post(caURL, payload).fail(function () { });
    } catch (e) { }

    // Use cached async getter to fetch config and decide console logging
    getConfig().then(function (cfg) {
        try {
            var displayLevel;
            var debugMode = cfg && (cfg.DEBUG_TO_LOG === 'true' || cfg.DEBUG_TO_LOG === true);
            if (debugMode) {
                displayLevel = '[' + (type || 'user') + '.' + (lvl || 'info') + ']';
            } else {
                switch (lvl) {
                    case 'debug':
                        if (cfg && (cfg.DEBUG_TO_LOG === 'false' || cfg.DEBUG_TO_LOG === false)) {
                            return; // Skip debug logs if disabled in config
                        }
                        displayLevel = '[DEBUG]';
                        break;
                    case 'err':
                    case 'error':
                        displayLevel = '[ERROR]';
                        break;
                    case 'warn':
                    case 'warning':
                        displayLevel = '[WARN]';
                        break;
                    case 'info':
                    default:
                        displayLevel = '[INFO]';
                }
            }
            msg = displayLevel + ' ' + msg;
            if (category !== undefined && category !== null && category !== '') {
                msg = '[' + category + '] ' + msg;
            }
            if (obj !== undefined && obj !== null && obj !== '' && obj !== 'null') {
                console.log('compose.manager: ' + msg, obj);
            } else {
                console.log('compose.manager: ' + msg);
            }
        } catch (e) { }
    });
}

// Shared helpers for file-tree picker positioning and scroll tracking.
// Used by settings page and editor modal picker overlays.
function composeGetScrollContainer(el) {
    // Find the closest ancestor that actually scrolls.
    var node = el;
    while (node && node !== document.body && node !== document.documentElement) {
        var style = window.getComputedStyle(node);
        var overflowY = style.overflowY;
        if ((overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight) {
            return node;
        }
        node = node.parentElement;
    }
    return window;
}

function composePositionFileTreeForInput($input, options) {
    var opts = options || {};
    var zIndex = (opts.zIndex !== undefined) ? opts.zIndex : 100010;
    var minWidth = (opts.minWidth !== undefined) ? opts.minWidth : 320;
    var addClass = (opts.addClass !== undefined) ? opts.addClass : true;

    var $picker = $input.next('.fileTree');
    if (!$picker.length || !$input.length) return;

    // Always position fixed so the picker stays aligned to the input in the viewport.
    // The input's bounding rect naturally updates when the modal scrolls.
    var $scrollTarget = $(window);
    var useFixed = true;

    if (addClass) {
        $picker.addClass('compose-filetree-popup');
    }

    // This helper currently only enforces width and stacking (z-index) for the picker.
    // It does not compute or apply top/left coordinates; initial placement is handled by
    // the underlying jquery.filetree integration / fileTreeAttach logic.
    $picker.css({
        width: Math.max($input.outerWidth(), minWidth) + 'px',
        zIndex: zIndex
    });
}

function composeTrackFileTreeForInput($input, options) {
    // Ensure any previous tracker is removed before attaching a new one.
    composeFileTreeRemoveTracker($input);

    var $picker = $input.next('.fileTree');
    if (!$picker.length) {
        return;
    }

    var scrollContainer = composeGetScrollContainer($input[0]);
    var $scrollTarget = (scrollContainer === window) ? $(window) : $(scrollContainer);

    var updatePosition = function () {
        composePositionFileTreeForInput($input, options);
    };

    // Listen for scroll/resize events so the picker stays aligned.
    $scrollTarget.on('scroll.composeFileTree resize.composeFileTree', updatePosition);

    var tracker = {
        $input: $input,
        update: updatePosition,
        cleanup: function () {
            $scrollTarget.off('.composeFileTree');
            $input.removeData('composeFileTreeTrackId');
            $input.removeData('composeFileTreeTrackHandlers');
        }
    };

    $input.data('composeFileTreeTrackId', tracker);
    $input.data('composeFileTreeTrackHandlers', { target: $scrollTarget });

    composeFileTreeTrackers.push(tracker);
    if (!composeFileTreeTrackRafId) {
        composeFileTreeTrackRafId = window.requestAnimationFrame(composeFileTreeRunTracks);
    }
}

function composeBindFileTreeInputs($inputs, options) {
    if (!$.fn.fileTreeAttach || !$inputs || !$inputs.length) return;

    $inputs.fileTreeAttach();
    $inputs.off('click.composeFileTree focus.composeFileTree').on('click.composeFileTree focus.composeFileTree', function () {
        var $input = $(this);
        setTimeout(function () {
            composePositionFileTreeForInput($input, options);
        }, FILE_TREE_POSITION_DELAY_MS);
    });
}
