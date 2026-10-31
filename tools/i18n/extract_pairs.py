#!/usr/bin/env python3
"""从 fork 基线(main 与 origin/main 的 merge-base)抽取 EN->ZH 对照表。

仅用于"初次建立翻译记忆库"。未来合并上游后**不要**盲目重跑本脚本——
上游改动过的英文会污染对照表。应当在实际翻译新句子时手动往 zh_CN.map.json 追加。
"""
import subprocess, re, difflib, json, sys, os

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def git(*args):
    return subprocess.run(["git", "-C", REPO, *args], capture_output=True, text=True).stdout


def file_lines(ref, path):
    r = subprocess.run(["git", "-C", REPO, "show", f"{ref}:{path}"],
                       capture_output=True, text=True)
    if r.returncode != 0:
        return None
    return r.stdout.splitlines()


def has_cjk(s):
    return any('\u4e00' <= c <= '\u9fff' for c in s)


STR_RE = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"")


def strings_of(line):
    return [m.group(1) if m.group(1) is not None else m.group(2)
            for m in STR_RE.finditer(line)]


# 这些英文是"被比较/被当数组键"的状态字与布尔字（docker/php 返回值、配置键等），
# 绝不能翻译——翻译它们会破坏逻辑（曾因此出现栈列表/统计失效的 bug）。
DENY = {
    "running", "stopped", "paused", "pausing", "starting", "stopping", "healthy",
    "unhealthy", "created", "restarting", "exited", "dead", "recreating", "removing",
    "building", "waiting", "pulling", "pushing", "extracting", "complete", "completed",
    "success", "successful", "failed", "failure", "error", "errors", "warning",
    "none", "all", "true", "false", "null", "yes", "no", "on", "off",
}


def norm_key(s):
    s = s.strip().strip('.!?:,;')
    return s.lower()


def main():
    base = git("merge-base", "main", "origin/main").strip()
    if not base:
        print("no merge-base found", file=sys.stderr)
        sys.exit(1)
    print(f"base = {base}")
    files = git("diff", "--name-only", base, "main", "--",
                "source/compose.manager").splitlines()
    print(f"changed files: {len(files)}")
    entries = []
    seen = set()
    for path in files:
        a = file_lines(base, path)
        b = file_lines("main", path)
        if a is None or b is None:
            continue
        sm = difflib.SequenceMatcher(None, a, b)
        for tag, i1, i2, j1, j2 in sm.get_opcodes():
            if tag != 'replace':
                continue
            for x, y in zip(a[i1:i2], b[j1:j2]):
                sa = strings_of(x)
                sb = strings_of(y)
                if not sa or not sb:
                    continue
                if has_cjk(" ".join(sa)):
                    continue
                if not has_cjk(" ".join(sb)):
                    continue
                en = max(sa, key=len)
                zh = max(sb, key=len)
                if len(en) < 4 or not re.search(r'[A-Za-z]', en):
                    continue
                if len(en) > 200 or len(zh) > 200:
                    continue
                # ZH 必须是真正的中文翻译
                if not has_cjk(zh):
                    continue
                # EN 不能本身含中文
                if has_cjk(en):
                    continue
                # 丢弃看起来像 HTML/标记/属性赋值 (会被错误配对) 的条目
                if re.search(r'[<>&=]', en):
                    continue
                # 丢弃状态字/布尔字等"不可翻译"的逻辑串
                if norm_key(en) in DENY:
                    continue
                if en in seen:
                    continue
                seen.add(en)
                entries.append({"en": en, "zh": zh, "file": path})
    out = os.path.join(REPO, "tools", "i18n", "zh_CN.map.json")
    with open(out, "w", encoding="utf-8") as f:
        json.dump({"version": 1, "entries": entries}, f,
                  ensure_ascii=False, indent=2)
    print(f"wrote {len(entries)} entries -> {out}")


if __name__ == "__main__":
    main()
