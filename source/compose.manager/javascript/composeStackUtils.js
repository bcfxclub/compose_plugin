/**
 * Compose Stack Utilities
 *
 * @file composeStackUtils.js
 * @module composeStackUtils
 *
 * Shared helpers for stack status derivation and related display logic.
 * Mirrors the PHP StackInfo::getStackState() / getContainerCounts() methods
 * so the live-refresh JS path stays consistent with server-rendered state.
 */

/**
 * Derive the aggregate display state of a stack from its containers.
 *
 * @param {Array} containers - Array of normalized container objects (must have `isRunning` boolean)
 * @returns {{state: string, runningCount: number, totalCount: number, label: string, shape: string, colorClass: string}}
 */
function deriveStackState(containers) {
    containers = containers || [];

    var runningCount = 0;
    var totalCount = containers.length;

    for (var i = 0; i < totalCount; i++) {
        if (containers[i] && containers[i].isRunning) {
            runningCount++;
        }
    }

    var state;
    if (runningCount > 0 && runningCount < totalCount) {
        state = 'partial';
    } else if (runningCount > 0) {
        state = 'started';
    } else {
        // Check for paused containers
        var anyPaused = containers.some(function (c) {
            return c && !c.isRunning && c.state === 'paused';
        });
        state = (anyPaused && totalCount > 0) ? 'paused' : 'stopped';
    }

    var label = state;
    if (state === 'partial') {
        label = '部分运行 (' + runningCount + '/' + totalCount + ')';
    } else {
        var labelMap = {
            stopped: '已停止',
            started: '运行中',
            paused: '已暂停',
            restarting: '重启中'
        };
        if (Object.prototype.hasOwnProperty.call(labelMap, state)) {
            label = labelMap[state];
        }
    }

    var shape, colorClass;
    switch (state) {
        case 'started':
            shape = 'play';
            colorClass = 'green-text';
            break;
        case 'partial':
            shape = 'exclamation-circle';
            colorClass = 'orange-text';
            break;
        case 'paused':
            shape = 'pause';
            colorClass = 'orange-text';
            break;
        default: // stopped
            shape = 'square';
            colorClass = 'grey-text';
            break;
    }

    return {
        state: state,
        runningCount: runningCount,
        totalCount: totalCount,
        label: label,
        shape: shape,
        colorClass: colorClass
    };
}
