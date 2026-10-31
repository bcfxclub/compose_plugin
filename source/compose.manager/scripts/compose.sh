#!/bin/bash
export HOME=/root

# Compose Manager - Docker Compose wrapper script
# Provides error handling, result tracking, and operation locking

# shellcheck disable=SC1091
. "$(dirname "$0")/common.sh"

# Configuration - can be overridden via environment
LOCK_TIMEOUT=${COMPOSE_LOCK_TIMEOUT:-30}
LOCK_DIR=${COMPOSE_LOCK_DIR:-/var/run/compose.manager}

SHORT=e:,c:,f:,p:,d:,o:,g:,s:,w:
LONG=env,command:,file:,project_name:,project_dir:,override:,profile:,debug,recreate,remove-orphans,stack-path:,workdir:,follow-logs,wait,wait-timeout:,build,credential-id:,git-commit:,save-local-changes,automatic-deploy-from:
OPTS=$(getopt -a -n compose --options $SHORT --longoptions $LONG -- "$@")

eval set -- "$OPTS"

envFile=""
env_args=()
file_args=()
profile_names=()
profile_args=()
project_directory=""
cmd_args=()
stack_path=""
debug=false
follow_logs=false
wait_for_healthy=false
wait_timeout=""
build_on_update=false
lock_fd=""
operation_exit_code=0
credential_id=""
docker_config_dir=""
git_commit=""
save_local_changes=false
automatic_deploy_from=""


# Logging helper — delegates to shared composeLogger, adds console echo in debug mode
log_msg() {
    local level="$1"
    local msg="$2"
    composeLogger "$msg" "${level,,}" compose
    if [ "$debug" = true ]; then
        echo "[$level] $msg"
    fi
}

# Locking functions to prevent concurrent operations on the same stack
acquire_lock() {
    local lock_name="$1"
    
    # Create lock directory if needed
    mkdir -p "$LOCK_DIR" 2>/dev/null
    
    local lock_file="$LOCK_DIR/${lock_name}.lock"
    
    # Open lock file for writing (fd 9)
    exec 9>"$lock_file"
    
    # Try to acquire lock with timeout
    local waited=0
    while ! flock -n 9 2>/dev/null; do
        if [ "$waited" -ge "$LOCK_TIMEOUT" ]; then
            log_msg "ERROR" "获取 $lock_name 的锁失败（已等待 ${LOCK_TIMEOUT}s）—— 可能有另一个操作正在进行"
            echo "✗ 该栈已有另一个操作正在进行，请稍候重试。"
            return 1
        fi
        
        if [ $waited -eq 0 ]; then
            echo "⏳ 正在等待另一个操作完成..."
        fi
        
        sleep 1
        waited=$((waited + 1))
    done
    
    # Write lock info
    echo "{\"pid\":$$,\"command\":\"$command\",\"time\":\"$(date -Iseconds)\"}" >&9
    
    lock_fd=9
    return 0
}

release_lock() {
    if [ -n "$lock_fd" ]; then
        flock -u $lock_fd 2>/dev/null
        exec 9>&-
        lock_fd=""
    fi
}

# Ensure lock is released and follow state is cleared on exit.
cleanup_docker_config() {
  if [ -n "$docker_config_dir" ]; then
    php "$(dirname "$0")/credential_config.php" --remove "$docker_config_dir" >/dev/null 2>&1 || true
    docker_config_dir=""
  fi
}

trap 'release_lock; clear_follow_pid; cleanup_docker_config' EXIT

# Save operation result to stack directory
save_result() {
    local result="$1"
    local exit_code="$2"
    local operation="$3"
    
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "{\"result\":\"$result\",\"exit_code\":$exit_code,\"operation\":\"$operation\",\"timestamp\":\"$(date -Iseconds)\"}" > "$stack_path/last_result.json"
    fi
}

save_follow_pid() {
    local pid="$1"
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "$pid" > "$stack_path/.compose_follow.pid"
    fi
}

clear_follow_pid() {
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        rm -f "$stack_path/.compose_follow.pid"
    fi
}

# Persist selected profile names for later operations (e.g. update target scope).
persist_running_profiles() {
  if [ -z "$stack_path" ] || [ ! -d "$stack_path" ]; then
    return
  fi

  if [ ${#profile_names[@]} -gt 0 ]; then
    printf '%s\n' "$(IFS=,; echo "${profile_names[*]}")" > "$stack_path/running_profiles"
  else
    rm -f "$stack_path/running_profiles"
  fi
}

while :
do
  case "$1" in
    -e | --env )
      envFile="$2"
      shift 2
      
      if [ -f "$envFile" ]; then
        echo "使用 .env：$envFile"
      else
        echo ".env 不存在：$envFile"
        exit 1
      fi

      env_args=("--env-file" "$envFile")
      ;;
    -c | --command )
      command="$2"
      shift 2
      ;;
    -f | --file )
      file_args+=("-f" "$2")
      shift 2
      ;;
    -p | --project_name )
      name="$2"
      shift 2
      ;;
    -d | --project_dir )
      if [ -d "$2" ]; then
        while IFS= read -r -d '' file; do
          file_args+=("-f" "$file")
        done < <(find "$2" -maxdepth 1 -type f \( -name '*compose*.yml' -o -name '*compose*.yaml' \) -print0)
      fi
      shift 2
      ;;
    -g | --profile )
      profile_names+=("$2")
      shift 2
      ;;
    -w | --workdir )
      if [ -d "$2" ]; then
        project_directory="$2"
      else
        log_msg "ERROR" "项目目录不存在：$2"
        exit 1
      fi
      shift 2
      ;;
    --recreate )
      cmd_args+=("--force-recreate")
      shift;
      ;;
    --remove-orphans )
      cmd_args+=("--remove-orphans")
      shift;
      ;;
    -s | --stack-path )
      stack_path="$2"
      shift 2
      ;;
    --debug )
      debug=true
      shift;
      ;;
    --follow-logs )
      follow_logs=true
      shift;
      ;;
    --wait )
      wait_for_healthy=true
      shift;
      ;;
    --wait-timeout )
      wait_timeout="$2"
      shift 2
      ;;
    --build )
      build_on_update=true
      shift;
      ;;
    --credential-id )
      credential_id="$2"
      shift 2
      ;;
    --git-commit )
      git_commit="$2"
      shift 2
      ;;
    --save-local-changes )
      save_local_changes=true
      shift;
      ;;
    --automatic-deploy-from )
      # The scheduled check's deploy, and the deployed commit it decided from ("none" if none).
      automatic_deploy_from="$2"
      shift 2
      ;;
    --)
      shift;
      break
      ;;
    *)
      echo "未知选项：$1"
      shift
      ;;
  esac
done

if [ -n "$credential_id" ]; then
  if ! credential_output=$(php "$(dirname "$0")/credential_config.php" --credential-id "$credential_id"); then
    log_msg "ERROR" "无法加载所选的注册表凭据"
    exit 1
  fi
  docker_config_dir=$(printf '%s\n' "$credential_output" | sed -n '1p')
  credential_label=$(printf '%s\n' "$credential_output" | sed -n '2p')
  export DOCKER_CONFIG="$docker_config_dir"
  log_msg "DEBUG" "为 $name 使用注册表凭据 '${credential_label:-$credential_id}'"
elif [[ "$command" =~ ^(up|pull|update|gitdeploy)$ ]]; then
  log_msg "DEBUG" "$name 未配置注册表凭据，将使用默认的 Docker 凭据"
fi

# Build docker compose profile flags from canonical profile names.
for profile_name in "${profile_names[@]}"; do
  profile_args+=("--profile" "$profile_name")
done

if [ -n "$project_directory" ]; then
  if ! cd "$project_directory" 2>/dev/null; then
    log_msg "ERROR" "无法切换到项目目录：$project_directory"
    exit 1
  fi
fi

# Build the compose base command as an array (no eval needed).
# When we need Docker Compose default discovery we intentionally run from the
# project directory itself so it matches plain `cd <dir> && docker compose ...`,
# which is the behavior the project was validated against.
compose_base=(docker compose "${env_args[@]}" "${file_args[@]}" "${profile_args[@]}")

# Canonicalize project name through shared PHP sanitizer.
if ! name=$(canonicalize_project_name "$name"); then
  log_msg "ERROR" "无法规范化项目名"
  exit 1
fi

# Acquire lock for operations that modify state (not for read-only commands)
case $command in
  up|down|pull|update|stop|gitdeploy)
    # Lock by canonical project name so every path uses the same stack identity.
    lock_name="$name"
    if ! acquire_lock "$lock_name"; then
      exit 1
    fi
    ;;
esac

# Optionally create missing `external: true` networks before starting the stack
# (Settings > Compose > Create Missing External Networks).
case $command in
  up|update)
    if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
      create_missing_external_networks "${compose_base[@]}" -p "$name"
    fi
    ;;
esac

case $command in

  up)
    if [ "$follow_logs" = true ] && [ "$wait_for_healthy" = true ]; then
      log_msg "ERROR" "同一次 compose up 中不能同时使用跟随日志与等待健康检查"
      echo "✗ 不能同时启用“跟随栈日志”和“等待健康检查”。"
      exit 1
    fi

    if [ "$wait_for_healthy" = true ]; then
      cmd_args+=("--wait")
      if [ -n "$wait_timeout" ]; then
        cmd_args+=("--wait-timeout" "$wait_timeout")
      fi
    fi

    if [ "$follow_logs" = true ]; then
      save_follow_pid "$$"
      if [ "$debug" = true ]; then
        log_msg "DEBUG" "${compose_base[*]} -p $name up ${cmd_args[*]}"
      fi
      "${compose_base[@]}" -p "$name" up "${cmd_args[@]}"
    else
      if [ "$debug" = true ]; then
        log_msg "DEBUG" "${compose_base[*]} -p $name up ${cmd_args[*]} -d"
      fi
      "${compose_base[@]}" -p "$name" up "${cmd_args[@]}" -d
    fi
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      # Save stack started timestamp and running profiles
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" $exit_code "up"
      echo ""
      echo "✓ 栈 $name 启动成功"
    else
      save_result "failed" $exit_code "up"
      log_msg "ERROR" "启动栈 $name 失败（退出码：$exit_code）"
      echo ""
      echo "✗ 栈 $name 启动失败（退出码：$exit_code）"
    fi
    ;;

  down)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name down ${cmd_args[*]}"
    fi
    
    "${compose_base[@]}" -p "$name" down "${cmd_args[@]}" 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      # Clear running profiles on successful down
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        rm -f "$stack_path/running_profiles"
      fi
      save_result "success" $exit_code "down"
      echo ""
      echo "✓ 栈 $name 停止成功"
    else
      save_result "failed" $exit_code "down"
      log_msg "ERROR" "停止栈 $name 失败（退出码：$exit_code）"
      echo ""
      echo "✗ 栈 $name 停止失败（退出码：$exit_code）"
    fi
    ;;

  pull)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name pull --ignore-buildable"
    fi
    
    "${compose_base[@]}" -p "$name" pull --ignore-buildable
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "pull"
      echo ""
      echo "✓ 已成功为 $name 拉取镜像"
    else
      save_result "failed" $exit_code "pull"
      log_msg "ERROR" "为 $name 拉取镜像失败（退出码：$exit_code）"
      echo ""
      echo "✗ 为 $name 拉取镜像失败（退出码：$exit_code）"
    fi
    ;;
    
  update)
    up_args=("-d")
    pull_args=()
    if [ "$build_on_update" = true ]; then
      # --ignore-buildable only makes sense when we rebuild those services ourselves.
      pull_args+=("--ignore-buildable")
      up_args+=("--build")
    fi

    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name images -q"
      log_msg "DEBUG" "${compose_base[*]} -p $name pull ${pull_args[*]}"
      log_msg "DEBUG" "${compose_base[*]} -p $name up ${up_args[*]}"
    fi

    # Capture current images for cleanup later
    images=()
    mapfile -t images < <("${compose_base[@]}" -p "$name" images -q 2>/dev/null)

    if [ "${#images[@]}" -eq 0 ]; then
      # Fallback: extract image names from compose files directly
      local_files=()
      for (( i=0; i<${#file_args[@]}; i++ )); do
        if [ "${file_args[$i]}" = "-f" ] && [ -f "${file_args[$((i+1))]}" ]; then
          local_files+=("${file_args[$((i+1))]}")
        fi
      done
      if (( ${#local_files[@]} )); then
        mapfile -t services < <(sed -n 's/image:\(.*\)/\1/p' "${local_files[@]}")
        for image in "${services[@]}"; do
          mapfile -t temp_images < <(docker images -q --no-trunc "${image}" 2>/dev/null)
          images+=( "${temp_images[@]}" )
        done
      fi

      images=( "${images[@]##sha256:}" )
    fi
    
    # Pull latest images. Buildable services are only skipped when we rebuild them below.
    echo "正在拉取最新镜像..."
    "${compose_base[@]}" -p "$name" pull "${pull_args[@]}"
    pull_exit=$?

    # Local-only images (build: + image:) can't be pulled; rebuild those from source instead.
    if [ $pull_exit -ne 0 ] && [ "$build_on_update" != true ]; then
      echo ""
      echo "拉取失败；正在跳过可构建服务重新拉取，并从源码重新构建..."
      log_msg "WARNING" "$name 拉取失败，正在用 --ignore-buildable 与 --build 重试"
      "${compose_base[@]}" -p "$name" pull --ignore-buildable
      pull_exit=$?
      if [ $pull_exit -eq 0 ]; then
        up_args+=("--build")
      fi
    fi
    
    if [ $pull_exit -ne 0 ]; then
      operation_exit_code=$pull_exit
      save_result "failed" $pull_exit "update"
      log_msg "ERROR" "为 $name 拉取镜像失败，更新中止"
      echo ""
      echo "✗ 为 $name 拉取镜像失败，更新已中止"
      exit $operation_exit_code
    fi
    
    # Recreate containers with new images
    echo ""
    echo "正在重建容器..."
    "${compose_base[@]}" -p "$name" up "${up_args[@]}"
    up_exit=$?

    if [ $up_exit -eq 0 ]; then
      operation_exit_code=0
      # Clean up old images
      mapfile -t new_images < <("${compose_base[@]}" -p "$name" images -q 2>/dev/null)
      for target in "${new_images[@]}"; do
        for i in "${!images[@]}"; do
          if [[ ${images[i]} = "$target" ]]; then
            unset 'images[i]'
          fi
        done
      done

      if (( ${#images[@]} )); then
        if [ "$debug" = true ]; then
          log_msg "DEBUG" "docker rmi ${images[*]}"
        fi
        echo ""
        echo "正在清理旧镜像..."
        docker rmi "${images[@]}" 2>/dev/null || true
      fi
      
      # Save stack started timestamp and running profiles after update
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" 0 "update"
      echo ""
      echo "✓ 栈 $name 更新成功"
    else
      operation_exit_code=$up_exit
      save_result "failed" $up_exit "update"
      log_msg "ERROR" "更新栈 $name 失败（退出码：$up_exit）"
      echo ""
      echo "✗ 栈 $name 更新失败（退出码：$up_exit）"
    fi
    ;;

  gitdeploy)
    # Deploy a git stack. Fetching, checking out, checking, pulling and
    # starting all happen under this one lock. Until "up" starts, any failure
    # leaves the containers untouched and the clone at its previous commit.
    git_helper="$(dirname "$0")/git_stack.php"
    # Run the git helper. PHP notices go to stderr, so stdout carries only its answer.
    git_stack() {
      php -d display_errors=stderr "$git_helper" "$@"
    }
    # After a failure before "up", check the previous commit out again and say
    # where the clone is now.
    put_back_previous_commit() {
      if git_stack restore "$stack_path" "$previous_commit"; then
        echo "已还原到上一个提交，未改动任何容器。"
      else
        log_msg "ERROR" "无法为 Git 栈 $name 还原提交 $previous_commit"
        echo "未改动任何容器，但无法还原上一个提交：克隆仍停留在新提交上。"
      fi
    }
    if [ -z "$stack_path" ] || [ ! -f "$stack_path/git.json" ]; then
      log_msg "ERROR" "gitdeploy 需要 Git 栈的栈文件夹（--stack-path）"
      echo "✗ $name 不是 Git 栈。"
      exit 1
    fi

    # 1. Fetch, check out and check the new commit (git_stack.php prints the
    #    commit that was checked out before, for step 2).
    prepare_args=("$stack_path" "$name")
    if [ -n "$git_commit" ]; then
      prepare_args+=("--commit" "$git_commit")
    fi
    if [ "$save_local_changes" = true ]; then
      prepare_args+=("--save-local-changes")
    fi
    for profile_name in "${profile_names[@]}"; do
      prepare_args+=("--profile" "$profile_name")
    done
    # The scheduled check's deploy: git_stack.php first checks, under this lock, that it still applies.
    if [ -n "$automatic_deploy_from" ]; then
      prepare_args+=("--automatic-from" "$automatic_deploy_from")
    fi
    previous_commit=$(git_stack prepare "${prepare_args[@]}")
    prepare_exit=$?
    if [ "$prepare_exit" -eq "$GIT_DEPLOY_EXIT_SKIPPED" ]; then
      # Not a failure: no result is saved, and the next scheduled check decides again.
      log_msg "INFO" "已跳过 $name 的自动部署：该部署已不再适用"
      echo ""
      echo "栈 $name 未自动部署，未改动任何容器。"
      exit "$GIT_DEPLOY_EXIT_SKIPPED"
    fi
    if [ "$prepare_exit" -ne 0 ]; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "$name 的 Git 部署在改动任何容器之前已停止"
      echo ""
      echo "✗ 栈 $name 未部署。未改动任何容器。"
      exit 1
    fi
    if ! [[ "$previous_commit" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "git_stack.php prepare 为 $name 输出的不是提交 ID"
      echo ""
      echo "✗ 栈 $name 未部署。未改动任何容器，但克隆可能已位于新提交。"
      exit 1
    fi

    # The compose files may have changed with the new commit, so build the
    # command from the stack as it is checked out now.
    git_compose_args=()
    git_args_file=$(mktemp)
    if ! git_stack compose-args "$stack_path" > "$git_args_file"; then
      rm -f "$git_args_file"
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "无法读取 Git 栈 $name 的 compose 文件"
      echo ""
      echo "✗ 栈 $name 未部署。"
      put_back_previous_commit
      exit 1
    fi
    mapfile -d '' -t git_compose_args < "$git_args_file"
    rm -f "$git_args_file"

    # docker runs in the compose file's folder, with the environment the
    # checks gave it, whatever started this deploy (compose-git, the web UI
    # or anything else). Otherwise ${PWD} in a compose file, or a variable in
    # the environment of whatever started compose.sh (which wins over the
    # stack's .env, and DOCKER_HOST could point at another daemon), could make
    # the deploy do something other than what was checked.
    if ! git_compose_folder=$(git_stack compose-folder "$stack_path") || ! cd "$git_compose_folder"; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "无法找到 Git 栈 $name 的 compose 文件所在目录"
      echo ""
      echo "✗ 栈 $name 未部署。"
      put_back_previous_commit
      exit 1
    fi
    git_environment=(env -i "PATH=$PATH" "HOME=$HOME" "LC_ALL=C" "PWD=$git_compose_folder")
    # DOCKER_CONFIG holds the registry credential chosen above; TERM only
    # changes how Compose draws its progress.
    for variable in DOCKER_CONFIG TERM; do
      if [ -n "${!variable:-}" ]; then
        git_environment+=("$variable=${!variable}")
      fi
    done
    git_docker=("${git_environment[@]}" docker)
    git_compose=("${git_docker[@]}" compose "${git_compose_args[@]}" "${profile_args[@]}")
    # create_missing_external_networks (common.sh) runs its docker network calls with it too.
    # shellcheck disable=SC2034 # read in common.sh
    network_docker_command=("${git_docker[@]}")

    # A deploy always builds services that have a build section (the new
    # commit may change their Dockerfile), as a step of its own before up.
    # up itself neither builds nor pulls again, whatever a service's
    # pull_policy says: a failure there would come after the point where the
    # previous commit can be put back. --pull missing still fetches an image
    # the pull step did not.
    up_args=("-d" "--remove-orphans" "--no-build" "--pull" "missing")
    if [ "$wait_for_healthy" = true ]; then
      up_args+=("--wait")
      if [ -n "$wait_timeout" ]; then
        up_args+=("--wait-timeout" "$wait_timeout")
      fi
    fi
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${git_compose[*]} -p $name pull --ignore-buildable"
      log_msg "DEBUG" "${git_compose[*]} -p $name build"
      log_msg "DEBUG" "${git_compose[*]} -p $name up ${up_args[*]}"
    fi

    # Remember the stack's current images, to remove the ones it no longer uses afterwards.
    old_images=()
    mapfile -t old_images < <("${git_compose[@]}" -p "$name" images -q 2>/dev/null)

    # 2. Pull and build images. If either fails, put the previous commit back:
    #    still no container has changed.
    echo "正在拉取镜像..."
    if ! "${git_compose[@]}" -p "$name" pull --ignore-buildable; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "为 Git 栈 $name 拉取镜像失败"
      echo ""
      echo "✗ 为 $name 拉取镜像失败。"
      put_back_previous_commit
      exit 1
    fi
    echo ""
    echo "正在构建镜像..."
    if ! "${git_compose[@]}" -p "$name" build; then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "为 Git 栈 $name 构建镜像失败"
      echo ""
      echo "✗ 为 $name 构建镜像失败。"
      put_back_previous_commit
      exit 1
    fi

    # 3. Ask whether every container should be recreated (the stack's
    #    "recreate on any change in its folder" setting).
    if ! git_up_extra=$(git_stack up-arguments "$stack_path" "$previous_commit"); then
      save_result "failed" 1 "gitdeploy"
      log_msg "ERROR" "无法比较 Git 栈 $name 的提交"
      echo ""
      echo "✗ 栈 $name 未部署。"
      put_back_previous_commit
      exit 1
    fi
    # Only the two answers git_stack.php gives are accepted, so a stray line
    # of output can never become an argument to up.
    case "$git_up_extra" in
      "")
        ;;
      "--force-recreate")
        up_args+=("--force-recreate")
        ;;
      *)
        save_result "failed" 1 "gitdeploy"
        log_msg "ERROR" "git_stack.php up-arguments 为 $name 输出了意外内容"
        echo ""
        echo "✗ 栈 $name 未部署。"
        put_back_previous_commit
        exit 1
        ;;
    esac

    # Optionally create missing `external: true` networks, as up and update do
    # (Settings > Compose > Create Missing External Networks). This is done
    # here, after every check, pull and build has passed, so a deploy that
    # stops earlier creates nothing. A network that cannot be created would make
    # up fail, so stop here, while the previous commit can still be put back.
    if plugin_setting_enabled CREATE_MISSING_EXTERNAL_NETWORKS; then
      if ! create_missing_external_networks "${git_compose[@]}" -p "$name"; then
        save_result "failed" 1 "gitdeploy"
        log_msg "ERROR" "无法为 Git 栈 $name 创建缺失的外部网络"
        echo ""
        echo "✗ 栈 $name 未部署：缺失的外部网络无法创建。未改动任何容器。"
        echo "上面已创建的网络会保留。"
        put_back_previous_commit
        exit 1
      fi
    fi

    # 4. Start the stack at the new commit.
    echo ""
    echo "正在启动容器..."
    "${git_compose[@]}" -p "$name" up "${up_args[@]}"
    up_exit=$?
    operation_exit_code=$up_exit
    # The skipped status means "nothing was changed"; a failed up must never read as that.
    if [ "$up_exit" -eq "$GIT_DEPLOY_EXIT_SKIPPED" ]; then
      operation_exit_code=1
    fi

    if [ $up_exit -eq 0 ]; then
      if ! git_stack finish "$stack_path" success; then
        log_msg "ERROR" "Git 栈 $name 已启动，但无法记录其已部署的提交"
        echo "⚠ 栈已启动，但无法记录已部署的提交。请再次部署以记录。"
      fi

      new_images=()
      mapfile -t new_images < <("${git_compose[@]}" -p "$name" images -q 2>/dev/null)
      superseded_images=()
      for old_image in "${old_images[@]}"; do
        still_used=false
        for new_image in "${new_images[@]}"; do
          if [ "$old_image" = "$new_image" ]; then
            still_used=true
          fi
        done
        if [ "$still_used" = false ]; then
          superseded_images+=("$old_image")
        fi
      done
      if (( ${#superseded_images[@]} )); then
        echo ""
        echo "正在清理旧镜像..."
        # Plain docker rmi leaves alone any image another container still uses.
        "${git_docker[@]}" rmi "${superseded_images[@]}" 2>/dev/null || true
      fi

      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
        persist_running_profiles
      fi
      save_result "success" 0 "gitdeploy"
      echo ""
      echo "✓ 栈 $name 部署成功"
    else
      # A half-finished up cannot be undone reliably, so it is not rolled back:
      # the commit is recorded as failed, for a person to fix and deploy again.
      if ! git_stack finish "$stack_path" failed; then
        log_msg "ERROR" "无法记录 Git 栈 $name 的失败部署"
      fi
      save_result "failed" $up_exit "gitdeploy"
      log_msg "ERROR" "部署 Git 栈 $name 失败（退出码：$up_exit）"
      echo ""
      echo "✗ 栈 $name 启动失败（退出码：$up_exit）。该提交已记录为失败（可用 compose-git status 查看）。"
      echo "请修复后重新部署，或用 --commit 部署一个已知可用的提交。"
    fi
    ;;

  stop)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name stop"
    fi
    
    "${compose_base[@]}" -p "$name" stop 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "stop"
      echo ""
      echo "✓ 栈 $name 停止成功"
    else
      save_result "failed" $exit_code "stop"
      log_msg "ERROR" "停止栈 $name 失败（退出码：$exit_code）"
      echo ""
      echo "✗ 栈 $name 停止失败（退出码：$exit_code）"
    fi
    ;;

  list) 
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose ls -a --format json"
    fi
    docker compose ls -a --format json 2>&1
    ;;

  ps)
    # Get all compose containers with their status/uptime
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker ps -a --filter label=com.docker.compose.project --format json"
    fi
    docker ps -a --filter 'label=com.docker.compose.project' --format json 2>&1
    ;;

  logs)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "${compose_base[*]} -p $name logs -f"
    fi
    "${compose_base[@]}" -p "$name" logs -f 2>&1
    exit_code=$?
    operation_exit_code=$exit_code
    if [ $exit_code -ne 0 ]; then
      log_msg "ERROR" "Failed to stream logs (exit code: $exit_code)"
    fi
    ;;

  *)
    echo "未知命令：$command"
    log_msg "ERROR" "Unknown command: $command (name: $name, files: ${file_args[*]})"
    exit 1
    ;;
esac

case $command in
  up|down|pull|update|stop|logs|gitdeploy)
    exit "${operation_exit_code:-0}"
    ;;
esac