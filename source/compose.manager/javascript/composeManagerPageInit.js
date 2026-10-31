// Initialize editor modal after DOM is fully loaded
$(function() {
    try {
        updateModalOffset();
        $(window).on('resize', updateModalOffset);
        initEditorModal();
    } catch (e) {
        composeLogger('Editor init error (non-fatal)', {
            error: e && e.toString()
        }, 'user', 'warn', 'editorInit');
    }
    // Attach Unraid folder/file browser to all path inputs on the page.
    // The picker popup needs manual positioning for modals and overlay stacks.
    if ($.fn.fileTreeAttach) {
        var $pathInputs = $('input[data-pickroot]');
        composeBindFileTreeInputs($pathInputs, {
            zIndex: 100010,
            minWidth: 320,
            addClass: true
        });
    }
});

// Reorder Compose section above Docker Containers if configured
// Runs in its own $(function) to avoid being blocked by editor init errors
$(function() {
    (function reorderComposeAboveDocker() {
        if (!showComposeOnTop) return;
        // In tabbed mode the sections live in separate tabs; reordering is not applicable
        if ($('.tabs').length) return;

        var $content = $('div.content');
        if (!$content.length) return;

        // Locate the two title divs by their text content
        // Use find() with a filter instead of children() to handle any nesting edge-cases
        var $dockerTitle = null;
        var $composeTitle = null;
        $content.children('div.title').each(function() {
            var txt = $(this).text().trim();
            if (!$dockerTitle && /Docker\s*Containers/i.test(txt)) $dockerTitle = $(this);
            if (!$composeTitle && /Compose/i.test(txt) && !/Docker/i.test(txt)) $composeTitle = $(this);
        });

        if (!$dockerTitle || !$composeTitle) {
            composeLogger('跳过将 Compose 移到 Docker 上方', {
                dockerTitle: !!$dockerTitle,
                composeTitle: !!$composeTitle
            }, 'user', 'warn', 'reorderComposeAboveDocker');
            return;
        }

        // Collect all nodes from the Compose title to the end of .content
        var composeNodes = [];
        var found = false;
        $content.contents().each(function() {
            if (this === $composeTitle[0]) found = true;
            if (found) composeNodes.push(this);
        });

        // Move them before the Docker title
        composeNodes.forEach(function(node) {
            $content[0].insertBefore(node, $dockerTitle[0]);
        });
    })();
});

// Hide compose-managed containers from Docker Containers table if configured
// Runs in its own $(function) to avoid being blocked by other init errors
$(function() {
    (function hideComposeContainersFromDocker() {
        if (!hideComposeFromDocker) return;

        function getComposeContainerNames() {
            var names = {};
            // Primary source: data-containers attribute on stack rows
            // (populated by PHP at list-load time — always available)
            $('#compose_stacks .compose-sortable[data-containers]').each(function() {
                try {
                    var list = JSON.parse($(this).attr('data-containers') || '[]');
                    for (var i = 0; i < list.length; i++) {
                        if (list[i]) names[list[i].toLowerCase()] = true;
                    }
                } catch (e) {}
            });
            // Fallback: stack detail rows (if any stacks have been expanded)
            $('#compose_stacks .stack-details-row').each(function() {
                $(this).find('tr[data-container]').each(function() {
                    var name = $(this).attr('data-container');
                    if (name) names[name.toLowerCase()] = true;
                });
            });
            // Fallback: stackUpdateStatus (populated after update checks)
            if (typeof stackUpdateStatus !== 'undefined') {
                for (var stackName in stackUpdateStatus) {
                    var info = stackUpdateStatus[stackName];
                    if (info.containers) {
                        for (var i = 0; i < info.containers.length; i++) {
                            var n = info.containers[i].name;
                            if (n) names[n.toLowerCase()] = true;
                        }
                    }
                }
            }
            return names;
        }

        function doHide() {
            var $dockerTable = $('#docker_list');
            if (!$dockerTable.length) return;

            var composeNames = getComposeContainerNames();
            if (Object.keys(composeNames).length === 0) return;

            $dockerTable.find('tr.sortable').each(function() {
                var $row = $(this);
                // Use a broad selector — just find the appname span anywhere in the first cell
                var rowName = $row.find('td:first span.appname').first().text().trim();
                if (!rowName) rowName = $row.find('td:first').text().trim();

                if (composeNames[rowName.toLowerCase()]) {
                    $row.hide();
                }
            });
        }

        // Re-run whenever compose list reloads (event fired by composeLoadlist)
        $(document).on('compose-list-loaded', doHide);

        // Watch for Docker table changes (rows load asynchronously via AJAX)
        // Use polling until #docker_list exists, then attach MutationObserver
        function attachDockerObserver() {
            var dockerTable = document.getElementById('docker_list');
            if (dockerTable) {
                var obs = new MutationObserver(function() {
                    setTimeout(doHide, 300);
                });
                obs.observe(dockerTable, {
                    childList: true,
                    subtree: true
                });
                // Initial run now that docker table exists
                setTimeout(doHide, 500);
            } else {
                // docker_list not in DOM yet — retry
                setTimeout(attachDockerObserver, 500);
            }
        }
        attachDockerObserver();

        // Also run after a generous delay as final fallback
        setTimeout(doHide, 4000);
    })();
});
