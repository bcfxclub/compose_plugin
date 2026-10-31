#!/usr/bin/env python3
"""扫描源码里仍残留的英文用户面文案，输出待翻译清单。

启发式、会有误报：刻意保留的英文（git/docker 输出匹配串、标识符、CSS 选择器等）
会被过滤，但无法 100% 准确。输出供人工过一遍，决定哪些要翻、哪些该加进"保留名单"。
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
    "compose manager", "host key verification failed",
}


def git(*args):
    return subprocess.run(["git", "-C", REPO, *args], capture_output=True, text=True).stdout


def has_cjk(s):
    return any('\u4e00' <= c <= '\u9fff' for c in s)


def is_comment(line):
    t = line.lstrip()
    return t.startswith(('#', '//', '/*', '*', '<!--', '-->'))


def norm_key(s):
    return s.strip().strip('.!?:,;').lower()


STR_RE = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"")


def main():
    data = json.load(open(MAP, encoding="utf-8"))
    known = set(e["en"] for e in data["entries"])
    files = git("ls-files", "--", "source/compose.manager").splitlines()
    found = []
    for path in files:
        full = os.path.join(REPO, path)
        if not os.path.isfile(full):
            continue
        for i, line in enumerate(open(full, encoding="utf-8", errors="replace"), 1):
            if is_comment(line):
                continue
            for m in STR_RE.finditer(line):
                s = m.group(1) if m.group(1) is not None else m.group(2)
                if not s.strip():
                    continue
                if has_cjk(s):
                    continue
                if s in known:
                    continue
                if norm_key(s) in DENY:
                    continue
                if len(s) < 4 or not re.search(r'[A-Za-z]', s):
                    continue
                # 排除路径 / URL
                if '/' in s:
                    continue
                # 排除 HTTP 头 / key: 形式
                if re.match(r'^[A-Za-z][\w-]*:', s):
                    continue
                # 排除页面指令 Tasks:61 / Docker:2
                if re.match(r'^\w+:\d+$', s):
                    continue
                # 排除全大写常量 / 全小写标识符（无空格）
                if s.isupper() or s.islower():
                    continue
                # 看起来像句子/标签：含空格、标点、或连续大写
                looks_text = (' ' in s) or re.search(r'[.!?:]', s) or re.search(r'[A-Z]{2,}', s)
                if not looks_text:
                    continue
                # 排除明显代码（变量/括号/标签）
                if re.search(r'[\$\(\)\{\}\[\]<>]', s):
                    continue
                found.append((path, i, s))
    seen = set()
    uniq = []
    for f in found:
        if f[2] in seen:
            continue
        seen.add(f[2])
        uniq.append(f)
    print(f"{len(uniq)} candidate untranslated strings:")
    for path, i, s in uniq[:300]:
        print(f"  {path}:{i}: {s!r}")


if __name__ == "__main__":
    main()
