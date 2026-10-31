<?PHP

/**
 * Compose Manager Main Page
 * The stack list is loaded asynchronously via AJAX for better UX
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/ColumnLayout.php");

// Load plugin config
$cfg = parse_plugin_cfg($sName);

// Resolve the saved column layout so the stack table renders in the user's
// chosen order/visibility/widths on first paint (no client-side reorder snap).
$columnLayout = compose_read_column_layout();
$columnModel = compose_column_client_model();
$stackColumnOrder = compose_stack_render_order($columnLayout);
$stackColumnMeta = compose_stack_column_meta();
$stackHiddenColumns = compose_stack_hidden_columns($columnLayout);
$stackWidthFractions = compose_stack_width_fractions($columnLayout);
composeLogger('Prepared column model bootstrap', [
    'stackCols' => count($columnModel['stackCols'] ?? []),
    'serviceCols' => count($columnModel['serviceCols'] ?? []),
    'stackVisible' => count($columnLayout['stackOrder'] ?? []),
    'serviceVisible' => count($columnLayout['serviceOrder'] ?? []),
], 'user', 'debug', 'column-layout');
$stackHideClass = '';
foreach ($stackHiddenColumns as $hiddenCol) {
    $stackHideClass .= ' hide-col-' . $hiddenCol;
}
$autoCheckUpdates = ($cfg['AUTO_CHECK_UPDATES'] ?? 'false') === 'true';
$autoCheckDays = floatval($cfg['AUTO_CHECK_UPDATES_DAYS'] ?? '1');
$showComposeOnTop = ($cfg['SHOW_COMPOSE_ON_TOP'] ?? 'false') === 'true';
$hideComposeFromDocker = ($cfg['HIDE_COMPOSE_FROM_DOCKER'] ?? 'false') === 'true';

// Get Docker Compose CLI version
$composeVersion = trim(shell_exec('docker compose version --short 2>/dev/null') ?? '');

// Host total memory in bytes for stack-level memory denominator.
$composeSystemMemBytes = 0;
$memKbRaw = trim(shell_exec("awk '/^MemTotal:/ {print \$2}' /proc/meminfo 2>/dev/null") ?? '');
if (is_numeric($memKbRaw)) {
    $composeSystemMemBytes = (int)$memKbRaw * 1024;
}

// CPU count for load normalization (matches Docker manager's cpu_list approach).
// cpu_list() returns thread_siblings_list entries (e.g. "0-3,8-11").
// We expand each range segment so "0-3" counts as 4, not 2 endpoints.
function compose_manager_cpu_spec_count($cpuSpec)
{
    $count = 0;
    foreach (explode(',', trim((string)$cpuSpec)) as $segment) {
        $segment = trim($segment);
        if ($segment === '') continue;
        if (strpos($segment, '-') !== false) {
            [$start, $end] = explode('-', $segment, 2);
            $start = (int)$start;
            $end   = (int)$end;
            if ($end < $start) [$start, $end] = [$end, $start];
            $count += max(0, $end - $start + 1);
        } else {
            $count += 1;
        }
    }
    return $count;
}

/** @disregard P1010 cpu_list is defined externally and imported here */
// @phpstan-ignore-next-line
$cpus = cpu_list();
$cpuCount = 0;
foreach ($cpus as $cpuSpec) {
    $cpuCount += compose_manager_cpu_spec_count($cpuSpec);
}
if ($cpuCount <= 0) {
    $cpuCount = (int)trim(shell_exec('nproc 2>/dev/null') ?: '1');
}
if ($cpuCount <= 0) {
    $cpuCount = 1;
}

// Note: Stack list is now loaded asynchronously via ComposeList.php
// This improves page load time by deferring expensive docker commands
?>

<?php /* Critical inline CSS
    Guarantees table-layout, column widths, and column visibility rules
    are applied synchronously BEFORE any HTML renders to prevent FOUC.
   Non-critical styles remain in comboButton.css loaded via <link>. */ ?>
<style>
    /* Table structure — always fixed layout */
    #compose_stacks {
        width: 100%;
        table-layout: fixed;
        /* Rendered width vars from ColumnLayout.php; keep runtime math here in sync with that model. */
        --cm-col-arrow-px: 24px;
        --cm-col-icon-px: 48px;
        --cm-col-fixed-px: calc(var(--cm-col-arrow-px) + var(--cm-col-icon-px));
<?php foreach ($stackWidthFractions as $fracCol => $fracVal): ?>
        --cm-col-<?php echo str_replace('_', '-', $fracCol); ?>-frac: <?php echo number_format($fracVal, 9, '.', ''); ?>;
<?php endforeach; ?>
    }

    /* Stabilize header row height across column visibility changes */
    #compose_stacks thead tr th {
        font-weight: normal;
        font-size: 1.1rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--dynamix-tablesorter-thead-th-text-color);
        background-color: var(--dynamix-tablesorter-thead-th-bg-color);
        padding: 8px 20px 8px 6px;
        white-space: nowrap;
        text-align: left;
    }

    /* Clip overflowing content in fixed-layout cells.
       border-box is REQUIRED so the percentage column widths below include
       their own padding; otherwise padding is added on top of the computed
       width and the columns overflow/shrink, leaving a gap at the right. */
    #compose_stacks th,
    #compose_stacks td {
        box-sizing: border-box;
        overflow: hidden;
        text-overflow: ellipsis
    }

    /* Stack-table column widths.
   Arrow + Icon are fixed px (small fixed content); remaining columns use CSS vars. */
    #compose_stacks thead th.col-arrow {
        width: var(--cm-col-arrow-px);
        padding: 0;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all {
        width: 24px;
        height: 24px;
        margin: 0 auto;
        padding: 0;
        border: 0;
        background: none;
        appearance: none;
        -webkit-appearance: none;
        border-radius: 4px;
        color: var(--dynamix-tablesorter-thead-th-text-color);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        line-height: 1;
        opacity: 0.95;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all i {
        transition: transform 0.2s ease;
        transform: rotate(0deg);
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all.is-expanded i {
        transform: rotate(180deg);
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:hover {
        color: var(--orange-text, var(--brand-orange));
        opacity: 1;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:focus-visible {
        outline: 1px solid var(--brand-orange);
        outline-offset: 2px;
    }

    #compose_stacks thead th.col-arrow .compose-stack-toggle-all:disabled {
        cursor: default;
        opacity: 0.45;
    }

    #compose_stacks td.col-arrow {
        text-align: center;
        white-space: nowrap;
    }

    #compose_stacks td.col-arrow i {
        vertical-align: middle;
    }

    #compose_list.compose-sort-enabled tr.compose-sortable {
        cursor: move;
    }

    #compose_stacks thead th.col-icon {
        width: var(--cm-col-icon-px);
        padding: 0;
    }

    #compose_stacks thead th.col-name {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-name-frac));
    }

    #compose_stacks thead th.col-update {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-update-frac));
    }

    #compose_stacks thead th.col-containers {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-containers-frac));
    }

    #compose_stacks thead th.col-uptime {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-uptime-frac));
    }

    #compose_stacks thead th.col-health {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-health-frac));
    }

    #compose_stacks thead th.col-autostart {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-autostart-frac));
    }

    #compose_stacks thead th.col-cpu {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-cpu-frac))
    }

    #compose_stacks thead th.col-memory {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-memory-frac))
    }

    #compose_stacks thead th.col-net_io {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-net-io-frac))
    }

    #compose_stacks thead th.col-block_io {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-block-io-frac))
    }

    #compose_stacks thead th.col-description {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-description-frac))
    }

    #compose_stacks thead th.col-path {
        width: calc((100% - var(--cm-col-fixed-px)) * var(--cm-col-path-frac))
    }

    /* Center the Containers column */
    #compose_stacks thead th.col-containers,
    #compose_stacks td.col-containers {
        text-align: center
    }

    /* Autostart column: right-align content to push toggles to edge */
    #compose_stacks thead th.col-autostart,
    #compose_stacks td.col-autostart {
        text-align: right
    }

    /* Arrow and icon columns: no overflow clipping, no padding bloat */
    #compose_stacks td.col-arrow,
    #compose_stacks td.col-icon {
        overflow: visible;
        padding: 8px 0;
        text-align: center;
        vertical-align: middle
    }

    /* Detail row */
    #compose_stacks .stack-details-cell {
        width: auto !important
    }

    #compose_stacks tbody tr.compose-sortable.compose-stack-row-alt,
    #compose_stacks tbody tr.compose-sortable.compose-stack-row-alt td {
        background-color: var(--dynamix-tablesorter-tbody-row-alt-bg-color)
    }

    #compose_stacks tbody tr.stack-details-row {
        background-color: var(--dynamix-sb-body-bg-color) !important
    }

    /* Autostart cell */
    #compose_stacks td.nine {
        white-space: nowrap;
        padding-right: 20px
    }

    .dropdown-menu {
        z-index: 100 !important;
    }

    /* Keep context menus above the fixed footer (z-index must out-rank the
       .dropdown-menu !important rule above); the scroll spacer appended in
       fixContextDropdownOverflow() keeps them reachable (unraid/webgui#2639) */
    .dropdown-context:not(.dropdown-context-sub) {
        z-index: 10001 !important;
    }

    /* CPU & Memory load display (matches Docker manager usage-disk style) */
    .compose-load-cell {
        white-space: nowrap;
        font-size: 0.9em;
    }

    .compose-load-cell .compose-load-cpu,
    .compose-load-cell .compose-load-mem {
        display: block;
    }

    .compose-load-cell .compose-load-mem {
        margin-top: 2px;
    }

    .compose-load-cell .usage-disk.mm {
        height: 3px;
        margin: 3px 20px 0 0;
        position: relative;
        background-color: var(--usage-disk-background-color, #e0e0e0);
    }

    .compose-load-cell .usage-disk.mm>span:first-child {
        position: absolute;
        left: 0;
        height: 3px;
        background-color: var(--gray-400, #888);
    }

    .compose-load-cell .usage-disk.mm>span:last-child {
        position: relative;
        z-index: 1;
    }
</style>

<?php
// Use Dynamix's bundled Ace if available (Unraid 7.0.0+), else fall back to our plugin-local copy
// (downloaded during install for pre-7.0.0 Unraid via the PLG post-install script)
$acePath = file_exists('/usr/local/emhttp/plugins/dynamix/javascript/ace/ace.js')
    ? '/webGui/javascript/ace'
    : '/plugins/compose.manager/javascript/ace';
?>
<script src="<?php autov($acePath . '/ace.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/js-yaml/js-yaml.min.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/common.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeIcons.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeSortable.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeStackUtils.js'); ?>" type="text/javascript"></script>
<?php if (file_exists('/usr/local/emhttp/plugins/docker.versions/styles/styles.css')): ?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/docker.versions/styles/styles.css'); ?>">
<?php endif; ?>
<?php if (file_exists('/usr/local/emhttp/plugins/docker.versions/scripts/changelog.js')): ?>
<script src="<?php autov('/plugins/docker.versions/scripts/changelog.js'); ?>" type="text/javascript"></script>
<?php endif; ?>
<script>
    window.composeManagerBootstrap = {
        compose_root: <?php echo json_encode($compose_root); ?>,
        aceTheme: <?php echo (in_array($theme, ['black', 'gray']) ? json_encode('ace/theme/tomorrow_night') : json_encode('ace/theme/tomorrow')); ?>,
        aceBasePath: <?php echo json_encode($acePath); ?>,
        icon_label: <?php echo json_encode($docker_label_icon); ?>,
        webui_label: <?php echo json_encode($docker_label_webui); ?>,
        shell_label: <?php echo json_encode($docker_label_shell); ?>,
        managed_label: <?php echo json_encode($docker_label_managed); ?>,
        managed_label_name: <?php echo json_encode($docker_label_managed_name); ?>,
        autoCheckUpdates: <?php echo json_encode($autoCheckUpdates); ?>,
        autoCheckDays: <?php echo json_encode($autoCheckDays); ?>,
        showComposeOnTop: <?php echo json_encode($showComposeOnTop); ?>,
        hideComposeFromDocker: <?php echo json_encode($hideComposeFromDocker); ?>,
        composeCliVersion: <?php echo json_encode($composeVersion); ?>,
        composeSystemMemBytes: <?php echo json_encode($composeSystemMemBytes); ?>,
        composeCpuCount: <?php echo json_encode($cpuCount); ?>,
        comboButtonCss: "<?php autov('/plugins/compose.manager/sheets/ComboButton.css'); ?>",
        editorModalCss: "<?php autov('/plugins/compose.manager/sheets/EditorModal.css'); ?>",
        columnModel: <?php echo json_encode($columnModel); ?>,
        columnLayout: <?php echo json_encode($columnLayout); ?>
    };

    var composeBootstrap = window.composeManagerBootstrap || {};
    var compose_root = composeBootstrap.compose_root || '';
    var caURL = "/plugins/compose.manager/include/Exec.php";
    var compURL = "/plugins/compose.manager/include/ComposeUtil.php";
    var aceTheme = composeBootstrap.aceTheme || 'ace/theme/tomorrow';
    var aceBasePath = composeBootstrap.aceBasePath || '/plugins/compose.manager/javascript/ace';
    const icon_label = composeBootstrap.icon_label || '';

    // Configure Ace base path explicitly so it finds mode/theme files
    // regardless of how the script URL was resolved
    if (typeof ace !== 'undefined') {
        ace.config.set('basePath', aceBasePath);
    }
    const webui_label = composeBootstrap.webui_label || '';
    const shell_label = composeBootstrap.shell_label || '';
    const managed_label = composeBootstrap.managed_label || '';
    const managed_label_name = composeBootstrap.managed_label_name || '';

    // Auto-check settings from config
    var autoCheckUpdates = !!composeBootstrap.autoCheckUpdates;
    var autoCheckDays = Number(composeBootstrap.autoCheckDays || 1);
    var showComposeOnTop = !!composeBootstrap.showComposeOnTop;
    var hideComposeFromDocker = !!composeBootstrap.hideComposeFromDocker;
    var composeCliVersion = composeBootstrap.composeCliVersion || '';
    var composeSystemMemBytes = Number(composeBootstrap.composeSystemMemBytes || 0);
    var composeCpuCount = Number(composeBootstrap.composeCpuCount || 1);
</script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeManagerPageInit.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeColumnCustomizer.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/qrcode/qrcode.min.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/credentialManager.js'); ?>" type="text/javascript"></script>
<script src="<?php autov('/plugins/compose.manager/javascript/composeManagerMain.js'); ?>" type="text/javascript"></script>

<HTML>

<HEAD>
</HEAD>

<BODY>

    <span class='tipsterallowed' hidden></span>
    <div class="TableContainer">
        <table id="compose_stacks" class="tablesorter shift<?php echo $stackHideClass; ?>" style="table-layout:fixed;width:100%">
            <thead>
                <tr>
                    <th class="col-arrow">
                        <div type="button" class="compose-stack-toggle-all" id="compose-stack-toggle-all" role="button" tabindex="0" onclick="toggleAllStackDetails(); return false;" title="展开所有栈" aria-label="展开所有栈">
                            <i class="fa fa-angle-double-down"></i>
                        </div>
                    </th>
                    <th class="col-icon"></th>
                    <th class="col-name">栈</th>
<?php foreach ($stackColumnOrder as $stackCol): ?>
<?php if (!isset($stackColumnMeta[$stackCol])) continue; ?>
                    <th class="<?php echo $stackColumnMeta[$stackCol]['thClass']; ?>"><?php echo htmlspecialchars($stackColumnMeta[$stackCol]['label'], ENT_QUOTES, 'UTF-8'); ?></th>
<?php endforeach; ?>
                    <th class="nine col-autostart">自动启动</th>
                </tr>
            </thead>
            <tbody id="compose_list">
                <tr>
                    <td colspan='14'></td>
                </tr>
            </tbody>
        </table>
    </div>
    <span class='tipsterallowed' hidden>
        <input type='button' value='新增栈' onclick='addStack();'>
        <input type='button' value='全部启动' onclick='startAllStacks();' id='startAllBtn'>
        <input type='button' value='全部停止' onclick='stopAllStacks();' id='stopAllBtn'>
        <input type='button' value='检查更新' onclick='checkAllUpdates();' id='checkUpdatesBtn'>
        <input type='button' value='全部更新' onclick='updateAllStacks();' id='updateAllBtn' disabled title='弹出含“后台运行”选项的对话框，然后更新选中的栈'>
        <label style='margin-left:10px;cursor:pointer;vertical-align:middle;' title='启用后仅影响启用了自动启动的栈'>
            <input type='checkbox' id='autostartOnlyToggle' style='vertical-align:middle;'>
            <span style='vertical-align:middle;'>仅自动启动</span>
        </label>
        <a href='/Settings/compose.manager.settings' style='margin-left:20px;'><input type='button' value='设置'></a>
    </span><br>

    <!-- Stack Actions Modal -->
    <div id="stack-actions-modal" class="stack-actions-modal" style="display:none;">
        <div class="stack-actions-modal-header">
            <span class="stack-actions-modal-title">栈操作</span>
            <button class="stack-actions-modal-close" onclick="closeStackActionsMenu();">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="stack-actions-modal-body">
            <button class="stack-action-item" onclick="executeStackAction('up');">
                <i class="fa fa-play"></i> Compose Up
            </button>
            <button class="stack-action-item" onclick="executeStackAction('down');">
                <i class="fa fa-stop"></i> Compose Down
            </button>
            <button class="stack-action-item" onclick="executeStackAction('update');">
                <i class="fa fa-refresh"></i> Update Stack
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('logs');">
                <i class="fa fa-file-text-o"></i> View Logs
            </button>
            <button class="stack-action-item" onclick="executeStackAction('viewCmdLog');">
                <i class="fa fa-list-alt"></i> View Last Cmd Log
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item" onclick="executeStackAction('editFiles');">
                <i class="fa fa-edit"></i> Edit Files
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editName');">
                <i class="fa fa-pencil"></i> Edit Name
            </button>
            <button class="stack-action-item" onclick="executeStackAction('editDesc');">
                <i class="fa fa-align-left"></i> Edit Description
            </button>
            <button class="stack-action-item" onclick="executeStackAction('uiLabels');">
                <i class="fa fa-tags"></i> UI Labels
            </button>
            <button class="stack-action-item" onclick="executeStackAction('settings');">
                <i class="fa fa-cog"></i> Stack Settings
            </button>
            <div class="stack-actions-divider"></div>
            <button class="stack-action-item stack-action-danger" onclick="executeStackAction('delete');">
                <i class="fa fa-trash"></i> Delete Stack
            </button>
        </div>
    </div>
    <div id="stack-actions-overlay" class="stack-actions-overlay" onclick="closeStackActionsMenu();" style="display:none;"></div>

    <!-- Editor Modal -->
    <div id="editor-modal-overlay" class="editor-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editor-modal-title">
        <div class="editor-modal">
            <!-- Modal Header -->
            <div class="editor-modal-header">
                <h2 class="editor-modal-title" id="editor-modal-title">编辑栈</h2>
                <button class="editor-modal-close" onclick="closeEditorModal()" aria-label="关闭编辑器">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <!-- Unified Tab Bar -->
            <div class="editor-tabs" role="tablist">
                <button class="editor-tab active" id="editor-tab-compose" onclick="switchTab('compose')" role="tab" aria-selected="true" aria-controls="editor-panel-compose">
                    <i class="fa fa-file-code-o" aria-hidden="true"></i>
                    Compose
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-env" onclick="switchTab('env')" role="tab" aria-selected="false" aria-controls="editor-panel-env">
                    <i class="fa fa-cog" aria-hidden="true"></i>
                    .env
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-labels" onclick="switchTab('labels')" role="tab" aria-selected="false" aria-controls="editor-panel-labels">
                    <i class="fa fa-tags" aria-hidden="true"></i>
                    <span id="editor-tab-labels-text">标签</span>
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-sources" onclick="switchTab('sources')" role="tab" aria-selected="false" aria-controls="editor-panel-sources">
                    <i class="fa fa-files-o" aria-hidden="true"></i>
                    Sources
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
                <button class="editor-tab" id="editor-tab-settings" onclick="switchTab('settings')" role="tab" aria-selected="false" aria-controls="editor-panel-settings">
                    <i class="fa fa-sliders" aria-hidden="true"></i>
                    Settings
                    <span class="editor-tab-modified" aria-hidden="true"></span>
                </button>
            </div>

            <!-- ========== COMPOSE EDITOR PANEL ========== -->
            <div class="editor-panel active" id="editor-panel-compose" role="group" aria-labelledby="editor-tab-compose">
                <div class="editor-modal-body">
                    <div id="editor-compose-git-banner" class="compose-status-warning" style="display:none;padding:6px 12px;font-size:0.9em;">
                        <i class="fa fa-code-fork"></i> <strong>这是一个 Git 栈。</strong> This file is in the plugin's clone of the repository: change it in the repository, then use Pull and Redeploy. An edit saved here is a local change, which the next deploy stops on (it can save it as a patch and discard it). Changes that must survive deploys belong in the Labels tab (the plugin's override) or the .env tab.
                    </div>
                    <div id="editor-compose-git-changed" class="compose-git-warning-box" style="display:none;margin:6px 12px;"></div>
                    <div id="compose-file-selector-wrap" style="display:none;align-items:center;gap:8px;padding:6px 12px;">
                        <label for="compose-file-selector" style="margin:0;">文件：</label>
                        <select id="compose-file-selector" onchange="switchComposeFile(this.value)"></select>
                    </div>
                    <div class="editor-container active" id="editor-container-compose">
                        <div id="editor-compose" style="width: 100%; height: 100%;"></div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-compose">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== ENV EDITOR PANEL ========== -->
            <div class="editor-panel" id="editor-panel-env" role="group" aria-labelledby="editor-tab-env">
                <div class="editor-modal-body">
                    <div class="editor-empty-state" id="env-empty-state">
                        <p>否<code>.env</code>该位置尚无文件。</p>
                        <button class="editor-action-btn" onclick="createEnvTemplate()">创建 .env 模板</button>
                    </div>
                    <div class="editor-file-wrap" id="env-editor-wrap">
                        <div class="editor-container active" id="editor-container-env">
                            <div id="editor-env" style="width: 100%; height: 100%;"></div>
                        </div>
                    </div>
                </div>
                <div class="editor-validation" id="editor-validation-env">
                    <i class="fa fa-check editor-validation-icon"></i> Ready
                </div>
            </div>

            <!-- ========== WEBUI LABELS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-labels" role="group" aria-labelledby="editor-tab-labels">
                <!-- Basic mode: form UI -->
                <div class="labels-panel" id="labels-basic-view">
                    <div class="labels-panel-header">
                        <div class="labels-panel-header-row">
                            <p>为每个服务配置图标、WebUI 链接和 Shell 命令。这些标签会把容器接入 unRAID 的 Docker 界面。</p>
                        </div>
                    </div>
                    <div id="labels-services-container">
                        <div class="labels-empty-state">
                            <i class="fa fa-spinner fa-spin"></i>
                            Loading services...
                        </div>
                    </div>
                </div>
                <!-- Advanced mode: raw override editor -->
                <div class="labels-override-view" id="labels-advanced-view" style="display:none;">
                    <div class="labels-override-header">
                        <div class="labels-override-header-row">
                            <div>
                                <span class="labels-override-title"><i class="fa fa-file-code-o"></i> compose.override.yaml</span>
                                <span class="labels-override-desc">正在编辑覆盖文件原文。此处的改动会直接保存，不影响“标签”表单视图。</span>
                            </div>
                        </div>
                    </div>
                    <div class="editor-empty-state" id="labels-override-empty-state">
                        <p>该位置尚无覆盖文件。</p>
                        <button class="editor-action-btn" onclick="createOverrideTemplate()">创建覆盖模板</button>
                    </div>
                    <div class="editor-file-wrap labels-override-editor-wrap" id="labels-override-editor-wrap">
                        <div id="editor-override" style="width:100%;height:100%;"></div>
                    </div>
                    <div class="editor-validation" id="editor-validation-override">
                        <i class="fa fa-check editor-validation-icon"></i> Ready
                    </div>
                </div>
            </div>

            <!-- ========== SOURCES PANEL ========== -->
            <div class="editor-panel" id="editor-panel-sources" role="group" aria-labelledby="editor-tab-sources">
                <div class="settings-panel">
                    <!-- Compose Sources & Files -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-files-o"></i>Compose 源与文件</div>

                        <!-- Shown instead of Compose Source for a git stack -->
                        <div class="settings-field" id="settings-git-source" style="display:none;">
                            <label>Git 仓库</label>
                            <div class="settings-field-help" style="margin-bottom:8px;">该栈从 Git 仓库部署，其 compose 文件就是插件克隆仓库中的那一份。</div>
                            <div id="settings-git-source-loading" class="compose-text-muted">加载中...</div>
                            <div id="settings-git-source-error" class="compose-status-danger" style="display:none;"></div>
                            <table id="settings-git-source-table" class="settings-git-source-table" style="display:none;">
                                <tbody>
                                    <tr><th>仓库</th><td><code id="settings-git-url"></code></td></tr>
                                    <tr><th>分支</th><td><code id="settings-git-branch"></code></td></tr>
                                    <tr><th>Compose 文件</th><td><code id="settings-git-compose-path"></code></td></tr>
                                    <tr><th>克隆</th><td><code id="settings-git-clone-dir"></code></td></tr>
                                    <tr id="settings-git-credential-row"><th>凭据</th><td id="settings-git-credential"></td></tr>
                                    <tr><th>已部署提交</th><td id="settings-git-deployed"></td></tr>
                                    <tr id="settings-git-failed-row"><th>失败的提交</th><td id="settings-git-failed" class="compose-status-danger"></td></tr>
                                    <tr id="settings-git-failed-automatic-row"><th>自动部署失败</th><td id="settings-git-failed-automatic" class="compose-status-danger"></td></tr>
                                    <tr><th>已检出</th><td id="settings-git-checked-out"></td></tr>
                                </tbody>
                            </table>
                            <div style="margin-top:10px;">
                                <label for="settings-git-auto-deploy" style="font-weight:normal;">自动部署</label>
                                <select id="settings-git-auto-deploy" onchange="saveGitAutoDeploy(this.value)">
                                    <option value="off">关闭</option>
                                    <option value="notify">有新提交改动该栈时通知我</option>
                                    <option value="deploy">部署改动该栈的新提交</option>
                                </select>
                                <span id="settings-git-auto-deploy-saved" class="compose-text-muted" style="display:none;margin-left:8px;"></span>
                                <div class="settings-field-help">每 15 分钟检查一次，改动后立即保存。部署失败会暂停自动部署，直到有一次部署成功，或在栈菜单中执行“恢复自动部署”。</div>
                            </div>
                            <div id="settings-git-local-changes" class="compose-git-warning-box" style="display:none;margin-top:8px;"></div>
                            <div id="settings-git-problem" class="compose-status-danger" style="display:none;margin-top:8px;"></div>
                            <div id="settings-git-deploy-key-wrap" style="display:none;margin-top:8px;">
                                <div class="settings-field-help" id="settings-git-deploy-key-label">该栈使用的部署密钥，与其仓库的其他 SSH 栈共用。需要在仓库中把它添加为只读部署密钥：</div>
                                <textarea id="settings-git-deploy-key" rows="2" readonly aria-labelledby="settings-git-deploy-key-label"></textarea>
                            </div>
                        </div>

                        <div class="settings-field" id="settings-compose-source-field">
                            <label>Compose 源</label>
                            <div class="settings-field-help" style="margin-bottom:8px;">该栈的 compose 文件位于何处？</div>
                            <div id="settings-compose-source-radios" style="display:flex;flex-direction:column;gap:6px;">
                                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                    <input type="radio" name="settings-compose-source" value="project" checked>
                                    <span>项目文件夹<span class="compose-text-muted" style="font-size:0.9em;">（默认 —— 文件位于该栈的项目目录中）</span></span>
                                </label>
                                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                    <input type="radio" name="settings-compose-source" value="folder">
                                    <span>外部文件夹<span class="compose-text-muted" style="font-size:0.9em;">（选择一个包含 compose 文件的目录）</span></span>
                                </label>
                                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                    <input type="radio" name="settings-compose-source" value="file">
                                    <span>指定 compose 文件<span class="compose-text-muted" style="font-size:0.9em;">（精确指定一个 <code>.yml</code>/<code>.yaml</code>）</span></span>
                                </label>
                            </div>

                            <div id="settings-external-compose-path-wrap" class="settings-compose-source-input" style="margin-top:10px;display:none;">
                                <input type="text" id="settings-external-compose-path" placeholder="/mnt/user/appdata/myapp/" data-pickroot="/" data-picktop="/mnt" data-pickfolders="true" data-pickcloseonfile="true">
                                <div class="settings-field-help">文件夹中必须包含匹配 <code>*compose*.yml</code> 的文件。要清除此设置，请把单选项切回“项目文件夹”。</div>
                            </div>

                            <div id="settings-external-compose-file-wrap" class="settings-compose-source-input" style="margin-top:10px;display:none;">
                                <input type="text" id="settings-external-compose-file" placeholder="/mnt/user/appdata/myapp/custom.compose.yml" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true" data-pickfilter="yml,yaml">
                                <div class="settings-field-help">路径必须在该栈的项目文件夹之外。</div>
                            </div>

                            <div id="settings-invalid-indirect-warning" class="compose-status-danger" style="margin-top:8px;display:none;padding:8px 12px;border-radius:4px;">
                                <span class="compose-status-danger" style="font-size:0.9em;"><i class="fa fa-exclamation-triangle"></i> <strong>无效的外部路径。</strong> 上方显示的路径已失效或找不到目标。请修正路径并保存以恢复该栈，或切回“项目文件夹”。</span>
                            </div>
                            <div id="settings-external-compose-info" style="margin-top:8px;display:none;">
                                <span class="compose-status-warning" style="font-size:0.9em;"><i class="fa fa-info-circle"></i> 该栈使用外部 compose 来源，编辑器的 Compose 标签会从该位置读取并保存。</span>
                            </div>
                            <div id="settings-external-compose-file-error" class="compose-status-danger" style="margin-top:6px;display:none;font-size:0.9em;"></div>
                            <div id="settings-convert-to-git" style="margin-top:12px;">
                                <button type="button" class="btn btn-sm" style="padding:2px 12px;font-size:0.9em;" onclick="openConvertToGitModal()"><i class="fa fa-code-fork"></i>将该栈迁移到 Git...</button>
                                <div class="settings-field-help">以后改为从 Git 仓库部署该栈。请先把它的 compose 文件放进仓库。</div>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-extra-compose-candidates">附加 Compose 文件</label>
                            <div id="settings-extra-compose-candidates" style="display:none;"></div>
                            <div id="settings-extra-compose-none" class="settings-field-help" style="display:none;">在 compose 源文件夹中未找到额外的 compose 文件（查找的是 <code>*compose*.yml</code> / <code>*compose*.yaml</code>）。</div>
                            <details id="settings-extra-compose-external-wrap" style="margin-top:8px;">
                                <summary style="cursor:pointer;">绝对路径（compose 源文件夹之外）</summary>
                                <textarea id="settings-extra-compose-external" rows="2" placeholder='每行一个绝对路径，例如 /mnt/user/appdata/shared/gpu.compose.yml'></textarea>
                            </details>
                            <div class="settings-field-help">选中的文件会作为额外的 <code>-f</code> 参数追加在主 compose 文件和覆盖文件之后（例如 GPU 或环境相关的覆盖）。设置此项会关闭默认的文件发现。</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-env-path">外部 ENV 文件路径</label>
                            <input type="text" id="settings-env-path" placeholder="默认（使用 compose 源目录中的 .env）" data-pickroot="/" data-picktop="/mnt" data-pickcloseonfile="true">
                            <div class="settings-field-help">外部 .env 文件的路径（例如 /mnt/user/appdata/myapp/.env）。留空则使用 compose 源文件夹（项目文件夹或外部文件夹）中的默认 .env 文件。</div>
                        </div>

                        <div class="settings-field">
                            <label>Compose 文件发现</label>
                            <div id="settings-discovery-mode-row" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <span id="settings-discovery-mode-badge" class="compose-status-info" style="padding:4px 10px;border:1px solid var(--dynamix-box-inner-div-border-color);border-radius:4px;font-size:0.9em;">发现模式：显式 <code>-f</code>参数</span>
                                <button type="button" id="settings-discovery-mode-toggle" class="btn btn-sm" style="padding:2px 12px;font-size:0.9em;">使用默认发现</button>
                            </div>
                            <div id="settings-default-compose-files-disabled-note" class="compose-status-warning" style="display:none;margin-top:8px;font-size:0.9em;"></div>
                            <div class="settings-field-help"><strong>默认发现</strong> 会让 Docker Compose 自动加载 <code>compose.override.*</code> 并遵循 <code>.env</code> 中的 <code>COMPOSE_FILE</code>。 <strong>显式<code>-f</code>参数</strong> 在插件逐个直接传入 compose 文件时使用。使用覆盖（外部 compose、额外文件、外部 env 路径）时必须为显式模式。</div>
                            <input type="checkbox" id="settings-use-default-compose-files" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true">
                        </div>

                        <div class="settings-field">
                            <label>实际执行的命令</label>
                            <div id="settings-effective-command-wrap" class="settings-effective-command-wrap">
                                <pre id="settings-effective-command" class="settings-effective-command">加载中…</pre>
                            </div>
                            <div class="settings-field-help settings-effective-command-note">
                                <span>反映当前<strong>已保存</strong>的配置。</span>
                                <span id="settings-effective-command-dirty" class="compose-status-warning settings-effective-command-dirty"><i class="fa fa-pencil"></i>有未保存的更改，应用后刷新。</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========== SETTINGS PANEL ========== -->
            <div class="editor-panel" id="editor-panel-settings" role="group" aria-labelledby="editor-tab-settings">
                <div class="settings-panel">
                    <!-- Stack Identity -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-info-circle"></i>栈标识</div>

                        <div class="settings-field">
                            <label for="settings-name">栈名称</label>
                            <input type="text" id="settings-name" placeholder='请输入栈名称'>
                            <div class="settings-field-help">界面中显示的名称，不影响项目文件夹名。</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-description">描述</label>
                            <textarea id="settings-description" placeholder="输入该栈的描述"></textarea>
                            <div class="settings-field-help">简要说明该栈的用途。</div>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-picture-o"></i>外观</div>

                        <div class="settings-field">
                            <label for="settings-icon-url">图标 URL / 路径</label>
                            <input type="text" id="settings-icon-url" placeholder="https://example.com/icon.png or /path/to/icon.png" data-pickroot="/" data-picktop="/boot/config/plugins/compose.manager/projects" data-pickcloseonfile="true" data-pickfilter="png,jpg,jpeg,gif,svg,ico,webp">
                            <div class="settings-field-help">该栈自定义图标的 URL 或本地路径。可用文件选择器或直接输入 URL。留空则使用默认图标。</div>
                            <div class="settings-field-icon-preview" id="settings-icon-preview" style="display:none;">
                                <span>预览：</span>
                                <img id="settings-icon-preview-img" src="" alt="Icon preview" onerror="this.parentElement.style.display='none';">
                            </div>
                        </div>
                    </div>

                    <!-- Stack WebUI -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-globe"></i>栈 WebUI</div>

                        <div class="settings-field">
                            <label for="settings-webui-url">WebUI 地址</label>
                            <input type="text" id="settings-webui-url" placeholder="http://tower.local:8080/">
                            <div class="settings-field-help">该栈主 WebUI 的 URL。这会在栈的右键菜单中添加“WebUI”项。 </div>
                            <div id="settings-webui-url-error" class="compose-status-danger" style="margin-top:6px;display:none;font-size:0.9em;"></div>
                            <div id="settings-webui-suggestion" style="display:none; margin-top:4px; padding:6px 10px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,165,0,0.3); border-radius:4px; font-size:0.9em;">
                                <span style="color:#aaa;">检测到：</span><code id="settings-webui-detected-url" style="user-select:all;"></code>
                                <span id="settings-webui-detected-source" style="color:#888; margin-left:6px; font-size:0.85em;"></span>
                                <button type="button" id="settings-webui-use-btn" class="btn btn-sm" style="margin-left:8px; padding:1px 10px; font-size:0.85em;">使用</button>
                            </div>
                        </div>
                    </div>

                    <!-- Runtime Defaults -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-cogs"></i>运行时默认值</div>

                        <div class="settings-field">
                            <label for="settings-default-profile">默认配置档</label>
                            <input type="text" id="settings-default-profile" placeholder="留空表示所有服务">
                            <div class="settings-field-help">
                                Comma-separated list of profiles to use by default for Autostart and multi-stack operations (e.g., "production,monitoring").
                                <br>Leave empty to start all services. Available profiles are auto-detected from your compose file.
                            </div>
                            <div id="settings-available-profiles" style="margin-top:8px;display:none;">
                                <span class="compose-text-muted" style="font-size:0.9em;">可用配置档：</span>
                                <span id="settings-profiles-list" style="font-family:var(--font-bitstream);"></span>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-credential-id">镜像仓库凭据</label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <select id="settings-credential-id" style="flex:1;"><option value="">匿名或无凭据</option></select>
                                <button type="button" id="settings-add-credential" title="添加凭据"><i class="fa fa-plus"></i></button>
                            </div>
                            <div class="settings-field-help">仅用于该栈可拉取镜像的操作。</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-wait-for-healthy">等待健康检查</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="settings-wait-for-healthy">
                                <span>为该栈使用 <code>docker compose up --wait</code></span>
                            </label>
                            <div class="settings-field-help">启用栈级的“等待健康检查”行为。不勾选则继承全局默认值。此项不能与“跟随栈日志”在同一命令中使用。</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-wait-timeout">等待超时</label>
                            <input type="number" id="settings-wait-timeout" min="10" max="1800" step="10" placeholder="300">
                            <div class="settings-field-help">等待栈变为健康状态的秒数。留空则使用全局默认超时。</div>
                        </div>

                        <div class="settings-field">
                            <label for="settings-build-on-update">更新时重建镜像</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="settings-build-on-update">
                                <span>添加<code>--build</code> to <code>docker compose up</code>更新时</span>
                            </label>
                            <div class="settings-field-help">仅对本地构建镜像的栈启用。如果栈在 <code>build:</code> 段旁同时发布了镜像，则不要勾选 —— 这类栈会改为拉取镜像，与直接执行 <code>docker compose up</code> 一致。</div>
                        </div>
                    </div>

                    <!-- Labels & Overrides -->
                    <div class="settings-section">
                        <div class="settings-section-title"><i class="fa fa-tags"></i>标签与覆盖</div>

                        <div class="settings-field">
                            <label for="settings-override-management">覆盖文件管理</label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                                <input type="checkbox" id="settings-override-management">
                                <span id="settings-override-management-label">自动</span>
                            </label>
                            <div class="settings-field-help"><strong>自动：</strong> 插件会管理 <code>compose.override.yaml</code> （位于项目目录），且“标签”标签页显示表单编辑器。 <strong>手动：</strong> 覆盖文件由你自行管理，自动编辑会被阻止，“标签”标签页显示 YAML 原文编辑器。</div>
                            <div id="settings-effective-override-path" style="margin-top:8px;display:none;">
                                <span class="compose-text-muted" style="font-size:0.9em;">生效的覆盖文件：</span>
                                <code id="settings-effective-override-path-value" style="font-size:0.9em;user-select:all;"></code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="editor-modal-footer">
                <div class="editor-footer-left">
                    <div class="editor-paths" id="editor-file-info">
                        <div class="editor-path-row">
                            <span class="editor-path-label">项目目录</span>
                            <span class="editor-path-value" id="editor-project-dir"></span>
                        </div>
                        <div class="editor-path-row">
                            <span class="editor-path-label">正在编辑文件</span>
                            <span class="editor-path-value" id="editor-edit-file"></span>
                        </div>
                    </div>
                    <span class="editor-shortcuts">
                        <kbd>Ctrl+S</kbd> Save &nbsp; <kbd>Esc</kbd> Close
                    </span>
                </div>
                <div class="editor-footer-right">
                    <span class="editor-change-count" id="editor-change-count">0 处改动</span>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-okay" onclick="handleOkayAction()">确定</button>
                    <button class="editor-btn editor-btn-save-all" id="editor-btn-apply" onclick="saveAllChanges(false)" disabled>应用</button>
                    <button class="editor-btn editor-btn-cancel" id="editor-btn-close" onclick="closeEditorModal()">关闭</button>
                </div>
            </div>
        </div>
    </div>

</BODY>

</HTML>