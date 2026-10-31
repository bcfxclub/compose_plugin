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
    "running", "stopped", "paused", "pausing", "starting", "stopping", "started",
    "healthy", "unhealthy", "created", "restarting", "exited", "dead", "recreating",
    "removing", "building", "waiting", "pulling", "pushing", "extracting", "complete",
    "completed", "success", "successful", "failed", "failure", "error", "errors",
    "warning", "none", "all", "true", "false", "null", "yes", "no", "on", "off",
    "partial", "label", "value", "name", "type", "key", "id", "text", "title",
    "compose manager",  # Unraid 通知事件名（-e 参数），非显示文案，必须保持英文
    "host key verification failed",  # 用于匹配 git 英文 stderr 输出，翻译即失效
}


def usable(en):
    """只接受'句子/短语'，绝不处理单字标识符/状态值。
    含空格、或以终止标点结尾才算可翻译文案；否则视为代码/状态串跳过。"""
    if norm_key(en) in DENY:
        return False
    if ' ' not in en and not re.search(r'[.!?:;…]$', en.strip()):
        return False
    return True


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
        if not usable(e["en"]):
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
        # 跳过二进制文件（含 NUL 字节），避免把图片等按文本重写损坏
        raw = open(full, "rb").read()
        if b"\x00" in raw:
            continue
        text = raw.decode("utf-8", errors="replace")
        lines = text.splitlines(keepends=True)
        new_lines = []
        for ln, line in enumerate(lines, 1):
            if is_comment(line):
                new_lines.append(line)
                continue

            def sub(m, _p=path, _l=ln):
                q = m.group(0)
                d = q[0]
                content = q[1:-1]
                if content in lookup and norm_key(content) not in DENY:
                    proposed.append((_p, _l, content, lookup[content]))
                    return d + lookup[content] + d
                return q

            new_lines.append(pat.sub(sub, line))
        new_text = "".join(new_lines)
        # 仅在确有改动时写回；newline="" 保留原换行符（CRLF/LF）
        if apply and new_text != text:
            open(full, "w", encoding="utf-8", newline="").write(new_text)
    if not proposed:
        print("nothing to replace (tree already Chinese or no match) — good.")
        return
    print(f"{'WOULD APPLY' if not apply else 'APPLIED'} {len(proposed)} replacements:")
    for path, ln, old, new in proposed[:200]:
        print(f"  {path}:{ln}: {old!r} -> {new!r}")


if __name__ == "__main__":
    main()
