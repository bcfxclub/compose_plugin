#!/usr/bin/env python3
"""把已知英文回灌成中文（安全版）。

默认 DRY-RUN：只报告"会改什么"，不写文件。确认无误后加 --apply 才真正改写。

安全策略：
1. 只替换"整段引号字符串内容"与对照表 EN 完全一致的情形，绝不做子串替换。
2. 跳过注释行（# // /* * <!--），避免把代码注释也翻了。
3. 对照表中若含状态字/布尔字（running/stopped/...）一律跳过——那些是
   与 docker/php 返回值或数组键比较的逻辑串，翻译会破坏功能。
4. 即便如此，--apply 后务必 `git diff` 人工过一眼再提交。
"""
import subprocess, re, json, os, sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MAP = os.path.join(REPO, "tools", "i18n", "zh_CN.map.json")

DENY = {
    "running", "stopped", "paused", "pausing", "starting", "stopping", "healthy",
    "unhealthy", "created", "restarting", "exited", "dead", "recreating", "removing",
    "building", "waiting", "pulling", "pushing", "extracting", "complete", "completed",
    "success", "successful", "failed", "failure", "error", "errors", "warning",
    "none", "all", "true", "false", "null", "yes", "no", "on", "off",
}


def git(*args):
    return subprocess.run(["git", "-C", REPO, *args], capture_output=True, text=True).stdout


def is_comment(line):
    t = line.lstrip()
    return t.startswith(('#', '//', '/*', '*', '<!--', '-->'))


def norm_key(s):
    return s.strip().strip('.!?:,;').lower()


def main():
    apply = "--apply" in sys.argv
    data = json.load(open(MAP, encoding="utf-8"))
    lookup = {}
    for e in data["entries"]:
        k = norm_key(e["en"])
        if k in DENY:
            continue
        lookup.setdefault(e["en"], e["zh"])  # 首次命中优先
    if not lookup:
        print("empty usable map", file=sys.stderr)
        sys.exit(1)
    files = git("ls-files", "--", "source/compose.manager").splitlines()
    pat = re.compile(r"('(?:\\.|[^'\\])*')|(\"(?:\\.|[^\"\\]|\\\\)*\")")
    proposed = []  # (path, line_no, old, new)
    for path in files:
        full = os.path.join(REPO, path)
        if not os.path.isfile(full):
            continue
        lines = open(full, encoding="utf-8", errors="replace").readlines()
        new_lines = []
        for ln, line in enumerate(lines, 1):
            if is_comment(line):
                new_lines.append(line)
                continue
            if not any(norm_key(m) not in DENY and m in lookup
                       for m in re.findall(r"'[^']*'|\"[^\"]*\"", line)):
                # 快速排除：本行没有可替换项
                # 仍要走精确匹配以防误判，这里仅做优化跳过
                new_lines.append(line)
                continue

            def sub(m):
                q = m.group(0)
                d = q[0]
                content = q[1:-1]
                if content in lookup and norm_key(content) not in DENY:
                    proposed.append((path, ln, content, lookup[content]))
                    return d + lookup[content] + d
                return q

            new_lines.append(pat.sub(sub, line))
        if apply:
            open(full, "w", encoding="utf-8").write("".join(new_lines))
    if not proposed:
        print("nothing to replace (tree already Chinese or no match) — good.")
        return
    print(f"{'WOULD APPLY' if not apply else 'APPLIED'} {len(proposed)} replacements:")
    for path, ln, old, new in proposed[:200]:
        print(f"  {path}:{ln}: {old!r} -> {new!r}")


if __name__ == "__main__":
    main()
