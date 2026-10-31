(function(window, $) {
    'use strict';

    var credentials = [];
    var onSaved = null;
    var githubPollTimer = null;
    var activeCredential = {};

    var PROVIDER_CONFIGS = {
        github: {
            label: 'GitHub 容器仓库（ghcr.io）',
            defaultRegistry: 'ghcr.io',
            registryReadonly: true,
            namePlaceholder: '例如：Work GitHub',
            usernamePlaceholder: 'GitHub 用户名或组织',
            secretPlaceholder: '个人访问令牌（ghp_... / github_pat_...）',
            tokenUrl: 'https://github.com/settings/tokens/new?scopes=read:packages&description=Unraid%20Compose%20Manager',
            tokenUrlLabel: '生成 GitHub 令牌',
            instructions: '使用上方的按钮一键登录，或生成一个具有 <code>read:packages</code> 权限的个人访问令牌。',
            canOAuth: true
        },
        docker: {
            label: 'Docker Hub（docker.io）',
            defaultRegistry: 'docker.io',
            registryReadonly: true,
            namePlaceholder: '例如：Docker Hub',
            usernamePlaceholder: 'Docker Hub 用户名（不是邮箱）',
            secretPlaceholder: '个人访问令牌（dckr_pat_...）',
            tokenUrl: 'https://app.docker.com/settings/personal-access-tokens/create',
            tokenUrlLabel: '生成 Docker Hub 访问令牌',
            instructions: '在 Docker Hub 账号设置中创建具有<b>只读</b>权限的个人访问令牌。用户名请填 Docker Hub 用户名（不是邮箱）。',
            canOAuth: false
        },
        gitlab: {
            label: 'GitLab 容器仓库（registry.gitlab.com）',
            defaultRegistry: 'registry.gitlab.com',
            registryReadonly: true,
            namePlaceholder: '例如：GitLab 镜像仓库',
            usernamePlaceholder: 'GitLab 用户名或部署令牌用户名',
            secretPlaceholder: '个人访问令牌或部署令牌密钥',
            tokenUrl: 'https://gitlab.com/-/user_settings/personal_access_tokens',
            tokenUrlLabel: '生成 GitLab 令牌',
            instructions: '创建具有 <code>read_registry</code> 权限的个人访问令牌，或使用项目/群组部署令牌。',
            canOAuth: false
        },
        quay: {
            label: 'Quay.io (quay.io)',
            defaultRegistry: 'quay.io',
            registryReadonly: true,
            namePlaceholder: '例如：Quay 镜像仓库',
            usernamePlaceholder: '机器人账号名（如 org+bot）或用户名',
            secretPlaceholder: '机器人账号令牌或密码',
            tokenUrl: 'https://quay.io/organization/',
            tokenUrlLabel: '打开 Quay 组织设置',
            instructions: '在 Quay 设置中创建具有读取权限的机器人账号，或创建应用令牌。',
            canOAuth: false
        },
        aws: {
            label: 'AWS 弹性容器仓库（ECR）',
            defaultRegistry: 'public.ecr.aws',
            registryReadonly: false,
            namePlaceholder: '例如：AWS ECR',
            usernamePlaceholder: 'AWS',
            secretPlaceholder: '认证令牌（来自 aws ecr get-login-password）',
            tokenUrl: 'https://console.aws.amazon.com/ecr/',
            tokenUrlLabel: '打开 AWS ECR 控制台',
            instructions: '私有 ECR 请把仓库地址设为 <code>&lt;account&gt;.dkr.ecr.&lt;region&gt;.amazonaws.com</code>，用户名填 <code>AWS</code>，令牌取自 <code>aws ecr get-login-password</code>。',
            canOAuth: false
        },
        azure: {
            label: 'Azure 容器仓库（ACR）',
            defaultRegistry: '',
            registryReadonly: false,
            namePlaceholder: '例如：Azure ACR',
            usernamePlaceholder: 'ACR 用户名或服务主体应用 ID',
            secretPlaceholder: '访问密钥密码或客户端密钥',
            tokenUrl: 'https://portal.azure.com/#blade/HubsExtension/BrowseResource/resourceType/Microsoft.ContainerRegistry%2Fregistries',
            tokenUrlLabel: '打开 Azure 门户',
            instructions: '请把仓库地址设为 <code>&lt;name&gt;.azurecr.io</code>，并在 ACR 的“访问密钥”或“令牌”中生成凭据。',
            canOAuth: false
        },
        gcr: {
            label: 'Google Artifact / Container 镜像仓库',
            defaultRegistry: 'gcr.io',
            registryReadonly: false,
            namePlaceholder: '例如：Google Artifact Registry',
            usernamePlaceholder: '_json_key',
            secretPlaceholder: '服务账号 JSON 密钥文件内容',
            tokenUrl: 'https://console.cloud.google.com/artifacts',
            tokenUrlLabel: '打开 Google Cloud 控制台',
            instructions: 'Artifact Registry（<code>&lt;region&gt;-docker.pkg.dev</code>）或 Container Registry（<code>gcr.io</code>）请使用用户名 <code>_json_key</code>，并粘贴服务账号的 JSON 密钥。',
            canOAuth: false
        },
        generic: {
            label: '其他 / 自定义镜像仓库',
            defaultRegistry: '',
            registryReadonly: false,
            namePlaceholder: '例如：自建镜像仓库',
            usernamePlaceholder: '镜像仓库用户名',
            secretPlaceholder: '密码或访问令牌',
            tokenUrl: '',
            tokenUrlLabel: '',
            instructions: '请输入自定义镜像仓库的主机名（例如 <code>registry.example.com</code>）、用户名，以及认证令牌或密码。',
            canOAuth: false
        },
        git: {
            label: 'Git 仓库（HTTPS 令牌，用于 Git 栈）',
            defaultRegistry: '',
            registryReadonly: false,
            namePlaceholder: '例如：GitHub 栈仓库',
            usernamePlaceholder: 'Git 用户名（使用 GitHub 令牌时可随意填写）',
            secretPlaceholder: '只读访问令牌',
            tokenUrl: '',
            tokenUrlLabel: '',
            instructions: '用于 Git 栈部署的私有仓库。主机名只填 Git 服务器，例如 <code>github.com</code> 或 <code>git.example.com:3000</code>。请使用仅能读取该仓库的令牌，例如具有内容读取权限的 GitHub 细粒度令牌。添加 Git 栈时选择它，或用 <code>compose-git</code>（<code>--credential</code>）指定给某个栈。',
            canOAuth: false
        },
        'git-ssh': {
            // Made by compose-git for the ssh stacks of a repository; never created or edited here.
            label: 'SSH 部署密钥（Git 栈）',
            defaultRegistry: '',
            registryReadonly: true,
            namePlaceholder: '',
            usernamePlaceholder: '',
            secretPlaceholder: '',
            tokenUrl: '',
            tokenUrlLabel: '',
            instructions: '',
            canOAuth: false
        }
    };

    function ensureModal() {
        if ($('#compose-credential-modal').length) return;
        $('body').append(
            '<div id="compose-credential-modal" class="credential-modal-backdrop" style="display:none;">' +
                '<div class="credential-modal" role="dialog" aria-modal="true" aria-labelledby="credential-modal-title">' +
                    '<div class="credential-modal-header"><h3 id="credential-modal-title">添加凭据</h3><button type="button" class="credential-close" title="Close"><i class="fa fa-times"></i></button></div>' +
                    '<input type="hidden" id="credential-id">' +
                    '<label for="credential-provider">提供方</label><select id="credential-provider">' +
                        '<option value="github">GitHub Container Registry (ghcr.io)</option>' +
                        '<option value="docker">Docker Hub (docker.io)</option>' +
                        '<option value="gitlab">GitLab Container Registry (registry.gitlab.com)</option>' +
                        '<option value="quay">Quay.io (quay.io)</option>' +
                        '<option value="aws">AWS Elastic Container Registry (ECR)</option>' +
                        '<option value="azure">Azure Container Registry (ACR)</option>' +
                        '<option value="gcr">Google Artifact / Container Registry</option>' +
                        '<option value="generic">Other / Custom Registry</option>' +
                        '<option value="git">Git repository (HTTPS token, for git stacks)</option>' +
                    '</select>' +
                    '<div id="credential-github-oauth-section">' +
                        '<button type="button" id="credential-github-signin"><i class="fa fa-github"></i>使用 GitHub 登录</button>' +
                        '<div id="credential-github-device" style="display:none;">' +
                            '<p>Enter this code on GitHub:</p>' +
                            '<div class="credential-github-code-row">' +
                                '<strong id="credential-github-code"></strong>' +
                                '<button type="button" id="credential-github-copy" title="复制代码"><i class="fa fa-copy"></i>复制</button>' +
                            '</div>' +
                            '<p class="credential-github-url">Go to <a id="credential-github-link" target="_blank" rel="noopener noreferrer"></a> on this or any other device</p>' +
                            '<div id="credential-github-qr-wrap"><div id="credential-github-qr"></div><span>Or scan with your phone</span></div>' +
                            '<p id="credential-github-status">Waiting for authorization...</p>' +
                        '</div>' +
                        '<div id="credential-oauth-connected" style="display:none;"><i class="fa fa-check-circle"></i> <span>GitHub account connected via OAuth</span> <button type="button" id="credential-github-renew">Renew access</button></div>' +
                    '</div>' +
                    '<div id="credential-provider-help" class="credential-provider-box">' +
                        '<div id="credential-provider-desc"></div>' +
                        '<div id="credential-provider-link-wrap" style="margin-top:8px;">' +
                            '<a id="credential-provider-token-link" class="credential-token-link" target="_blank" rel="noopener noreferrer">' +
                                '<i class="fa fa-external-link"></i> <span id="credential-provider-token-label">Generate Token</span>' +
                            '</a>' +
                        '</div>' +
                    '</div>' +
                    '<div id="credential-details">' +
                        '<label for="credential-name">Credential Name</label><input id="credential-name" type="text" autocomplete="off" placeholder="Work GitHub">' +
                        '<label for="credential-registry">Registry Host</label><input id="credential-registry" type="text" autocomplete="off" placeholder="ghcr.io">' +
                        '<label for="credential-username">Username</label><input id="credential-username" type="text" autocomplete="username">' +
                    '</div>' +
                    '<div id="credential-secret-wrap"><label for="credential-secret">Access token / Password</label><input id="credential-secret" type="password" autocomplete="new-password" placeholder="新建凭据时必填"><div class="credential-help">Use a read-only token whenever possible. Existing tokens are encrypted and never shown.</div></div>' +
                    '<div id="credential-modal-error" class="compose-status-danger" style="display:none;"></div>' +
                    '<div class="credential-modal-actions"><button type="button" class="credential-cancel">取消</button><button type="button" class="credential-save">Save credential</button></div>' +
                '</div>' +
            '</div>'
        );
        if (!$('#compose-credential-styles').length) {
            $('head').append('<style id="compose-credential-styles">' +
                '.credential-modal-backdrop{position:fixed;inset:0;z-index:10003;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:20px;background:var(--modal-overlay-bg);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}' +
                '.credential-modal{display:flex;flex-direction:column;box-sizing:border-box;width:min(560px,95vw);max-height:calc(100vh - 40px);overflow-y:auto;padding:24px 28px;color:var(--text-color);background-color:var(--background-color);border:1px solid var(--border-color);border-radius:8px;box-shadow:var(--panel-box-shadow);font-family:inherit}' +
                '.credential-modal-header{display:flex;align-items:center;justify-content:space-between;margin:-24px -28px 20px;padding:12px 20px;background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border-bottom:1px solid var(--border-color);border-radius:8px 8px 0 0}' +
                '.credential-modal-header h3{margin:0;color:var(--brand-orange);font-size:1.25rem;font-weight:600}' +
                '.credential-close{padding:5px 10px;color:var(--alt-text-color);background:none;border:0;cursor:pointer;font-size:1.5rem;line-height:1}.credential-close:hover{color:var(--brand-red)}.credential-close:focus-visible{outline:2px solid var(--brand-orange);outline-offset:2px}' +
                '.credential-modal label{display:block;margin:14px 0 8px;color:var(--text-color);font-size:1.05rem;font-weight:600}' +
                '.credential-modal input,.credential-modal select{box-sizing:border-box;width:100%;max-width:100%;padding:12px 16px;color:var(--text-color);background-color:var(--input-background-color);border:1px solid var(--border-color);border-radius:6px;font:inherit;transition:border-color .2s ease,box-shadow .2s ease}' +
                '.credential-modal input:hover,.credential-modal select:hover{border-color:var(--border-hover-color)}.credential-modal input:focus,.credential-modal select:focus{outline:none;border-color:var(--brand-orange);box-shadow:0 0 0 3px var(--brand-focus-ring)}.credential-modal input::placeholder{color:var(--placeholder-color)}.credential-modal input[readonly]{color:var(--alt-text-color);background-color:var(--disabled-input-background-color)}' +
                '.credential-help{margin-top:8px;color:var(--alt-text-color);font-size:.95rem;line-height:1.4}' +
                '.credential-provider-box{margin-top:12px;padding:12px 14px;color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px;font-size:.95rem;line-height:1.4}' +
                '.credential-provider-box code{padding:2px 5px;background:var(--input-background-color);border:1px solid var(--border-color);border-radius:3px;font-family:inherit;font-size:.9em}' +
                '.credential-token-link{display:inline-flex;align-items:center;gap:6px;color:var(--brand-orange);font-weight:600;text-decoration:none}' +
                '.credential-token-link:hover{text-decoration:underline;color:var(--brand-red)}' +
                '.credential-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin:22px -28px -24px;padding:12px 20px;background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border-top:1px solid var(--border-color);border-radius:0 0 8px 8px}' +
                '.credential-modal-actions button,#credential-github-signin{padding:10px 25px;border:0;border-radius:4px;cursor:pointer;font-size:.95rem}' +
                '.credential-cancel{color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color)}.credential-cancel:hover{background-color:var(--border-color)}' +
                '.credential-save,#credential-github-signin{color:var(--action-primary-color);background-color:var(--action-primary-bg)}.credential-save:hover,#credential-github-signin:hover{background-color:var(--action-primary-bg-hover)}.credential-save:disabled,#credential-github-signin:disabled{cursor:not-allowed;opacity:.5}' +
                '.credential-list-actions{white-space:nowrap}.credential-empty{padding:22px;text-align:center;color:var(--alt-text-color)}' +
                '#credential-github-signin{width:100%;margin-top:12px}' +
                '#credential-github-device{margin-top:12px;padding:14px 16px;color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px}' +
                '#credential-github-device p{margin:0 0 10px;color:var(--alt-text-color)}#credential-github-device p:last-child{margin:10px 0 0}#credential-github-code{color:var(--brand-orange);font-size:1.3rem;letter-spacing:0}#credential-github-link{margin-left:0}' +
                '.credential-github-code-row{display:flex;align-items:center;gap:12px}' +
                '#credential-github-copy{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;color:var(--text-color);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:4px;cursor:pointer;font-size:.9rem}#credential-github-copy:hover{border-color:var(--brand-orange)}' +
                '.credential-github-url{word-break:break-word}' +
                '#credential-github-qr-wrap{display:flex;flex-direction:column;align-items:center;gap:6px;margin:12px 0}#credential-github-qr-wrap span{color:var(--alt-text-color);font-size:.85rem}' +
                '#credential-github-qr{padding:8px;background:#fff;border:1px solid var(--border-color);border-radius:6px;line-height:0}#credential-github-qr svg{display:block;width:150px;height:150px}' +
                '#credential-oauth-connected{margin-top:12px;padding:10px 12px;color:var(--status-success);background-color:var(--dynamix-tablesorter-tbody-row-alt-bg-color);border:1px solid var(--border-color);border-radius:6px;display:flex;align-items:center;flex-wrap:wrap;gap:8px}#credential-oauth-connected i{margin-right:0}#credential-oauth-connected span{flex:1 1 auto}' +
                '#credential-github-renew{padding:6px 14px;color:var(--text-color);background-color:var(--input-background-color);border:1px solid var(--border-color);border-radius:4px;cursor:pointer;font-size:.9rem}#credential-github-renew:hover{border-color:var(--brand-orange)}#credential-github-renew:disabled{cursor:not-allowed;opacity:.5}' +
                '#credential-modal-error{margin-top:12px;padding:10px 12px;border-radius:6px}' +
                '@media(max-width:600px){.credential-modal-backdrop{padding:10px}.credential-modal{width:100%;max-height:calc(100vh - 20px);padding:20px}.credential-modal-header{margin:-20px -20px 16px}.credential-modal-actions{margin:20px -20px -20px}.credential-modal-actions button{padding:10px 16px}}' +
            '</style>');
        }
        $('.credential-close,.credential-cancel').on('click', closeModal);
        $('#credential-provider').on('change', function() {
            applyProviderDefaults();
        });
        $('.credential-save').on('click', saveCredential);
        $('#credential-github-signin').on('click', function() { startGitHubSignIn(false); });
        $('#credential-github-renew').on('click', function() { startGitHubSignIn(true); });
        $('#credential-github-copy').on('click', function() {
            var $copyButton = $(this);
            copyGitHubCode($('#credential-github-code').text(), function() {
                var original = $copyButton.html();
                $copyButton.html('<i class="fa fa-check"></i> Copied');
                window.setTimeout(function() { $copyButton.html(original); }, 1500);
            });
        });
    }

    function applyProviderDefaults() {
        var provider = $('#credential-provider').val() || 'github';
        var config = PROVIDER_CONFIGS[provider] || PROVIDER_CONFIGS.generic;
        var isOAuthGitHub = provider === 'github' && activeCredential.authMethod === 'oauth_device';
        var isNewCredential = !$('#credential-id').val();
        var isNewGitHub = provider === 'github' && isNewCredential;

        $('#credential-github-oauth-section').toggle(isNewGitHub || isOAuthGitHub);
        $('#credential-github-signin').toggle(isNewGitHub);
        $('#credential-github-device').hide();
        $('#credential-oauth-connected').toggle(isOAuthGitHub);

        $('#credential-provider-help').toggle(!isOAuthGitHub);
        $('#credential-provider-desc').html(config.instructions);
        if (config.tokenUrl) {
            $('#credential-provider-token-link').attr('href', config.tokenUrl);
            $('#credential-provider-token-label').text(config.tokenUrlLabel);
            $('#credential-provider-link-wrap').show();
        } else {
            $('#credential-provider-link-wrap').hide();
        }

        $('#credential-details').show();
        $('#credential-secret-wrap').toggle(!isOAuthGitHub);
        $('.credential-save').toggle(!isOAuthGitHub);
        $('.credential-cancel').text(isOAuthGitHub ? 'Close' : 'Cancel');
        $('#credential-provider').prop('disabled', isOAuthGitHub);
        $('#credential-name,#credential-username').prop('readonly', isOAuthGitHub);

        $('#credential-name').attr('placeholder', config.namePlaceholder);
        $('#credential-username').attr('placeholder', config.usernamePlaceholder);
        $('#credential-secret').attr('placeholder', isNewCredential ? config.secretPlaceholder : '留空则保留现有令牌');

        if (config.registryReadonly) {
            $('#credential-registry').val(config.defaultRegistry).prop('readonly', true);
        } else {
            $('#credential-registry').prop('readonly', false);
            if (!$('#credential-registry').val() && config.defaultRegistry) {
                $('#credential-registry').val(config.defaultRegistry);
            }
        }
    }

    function closeModal() {
        if (githubPollTimer) window.clearTimeout(githubPollTimer);
        githubPollTimer = null;
        $('#compose-credential-modal').hide();
    }

    function copyGitHubCode(code, onCopied) {
        function copyFallback() {
            var textarea = document.createElement('textarea');
            textarea.value = code;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            var copied = false;
            try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
            document.body.removeChild(textarea);
            return copied;
        }
        function notify(copied) {
            if (copied && typeof onCopied === 'function') onCopied();
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code).then(function() {
                notify(true);
            }).catch(function() {
                notify(copyFallback());
            });
            return;
        }
        notify(copyFallback());
    }

    function renderGitHubQr(url) {
        var $container = $('#credential-github-qr').empty();
        if (!url || typeof window.qrcode !== 'function') {
            $('#credential-github-qr-wrap').hide();
            return;
        }
        try {
            var qr = window.qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            $container.html(qr.createSvgTag(4, 4));
            $('#credential-github-qr-wrap').show();
        } catch (error) {
            $('#credential-github-qr-wrap').hide();
        }
    }

    function startGitHubSignIn(renew) {
        var credentialId = renew && activeCredential.id ? activeCredential.id : '';
        var $button = (credentialId ? $('#credential-github-renew') : $('#credential-github-signin')).prop('disabled', true);
        $('#credential-modal-error').hide();
        var payload = { action: 'startGitHubDeviceAuth' };
        if (credentialId) payload.credentialId = credentialId;
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', payload).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            if (response.result !== 'success') {
                $('#credential-modal-error').text(response.message || '无法启动 GitHub 登录。').show();
                $button.prop('disabled', false);
                return;
            }
            var device = response.device;
            $('#credential-github-code').text(device.userCode);
            $('#credential-github-link').attr('href', device.verificationUri).text(device.verificationUri);
            $('#credential-github-status').text('正在等待授权...');
            $('#credential-github-device').show();
            renderGitHubQr(device.verificationUriComplete || device.verificationUri);
            copyGitHubCode(device.userCode, function() {
                $('#credential-github-status').text('代码已复制，正在等待授权...');
            });
            window.open(device.verificationUri, '_blank', 'noopener');
            pollGitHubSignIn(device.state, device.interval || 5, credentialId);
        }).fail(function() {
            $('#credential-modal-error').text('无法连接 GitHub 登录服务。').show();
            $button.prop('disabled', false);
        });
    }

    function pollGitHubSignIn(state, interval, credentialId) {
        var $button = credentialId ? $('#credential-github-renew') : $('#credential-github-signin');
        githubPollTimer = window.setTimeout(function() {
            $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'pollGitHubDeviceAuth', state: state }).done(function(data) {
                var response;
                try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
                if (response.result !== 'success') {
                    $('#credential-github-status').text(response.message || 'GitHub 登录失败。');
                    $button.prop('disabled', false);
                    return;
                }
                var auth = response.auth || {};
                if (auth.status === 'pending') {
                    pollGitHubSignIn(state, auth.interval || interval, credentialId);
                    return;
                }
                if (auth.status === 'success') {
                    var callback = onSaved;
                    loadCredentials(function() {
                        if (callback) callback(auth.credential);
                        openModal(auth.credential, null);
                    });
                    return;
                }
                $('#credential-github-status').text(auth.status === 'denied' ? '授权被拒绝。' : '授权已过期，请重试。');
                $button.prop('disabled', false);
            }).fail(function() {
                $('#credential-github-status').text('无法检查授权，正在重试...');
                pollGitHubSignIn(state, interval, credentialId);
            });
        }, Math.max(5, interval) * 1000);
    }

    // options.registryOnly: opened from a stack's registry credential picker, so the git repository
    // kind is not offered. A git credential cannot be chosen there, and picking one would leave the
    // picker on "缺少凭据", which clears the stack's registry credential when saved.
    function openModal(credential, callback, options) {
        ensureModal();
        credential = credential || {};
        options = options || {};
        activeCredential = credential;
        onSaved = callback || null;
        $('#credential-modal-title').text(credential.id ? '编辑凭据' : '添加凭据');
        $('#credential-id').val(credential.id || '');
        // A saved credential cannot change between a registry login and a git credential (a
        // stack using it would break), so Edit offers neither change.
        var editingGit = !!credential.id && credential.provider === 'git';
        var hideGit = options.registryOnly === true || (!!credential.id && !editingGit);
        $('#credential-provider option[value="git"]')
            .prop('disabled', hideGit)
            .prop('hidden', hideGit);
        $('#credential-provider').val(credential.provider || 'github').prop('disabled', false);
        $('#credential-name').val(credential.name || '');
        $('#credential-registry').val(credential.registry || '');
        $('#credential-username').val(credential.username || '');
        $('#credential-secret').val('');
        $('#credential-github-signin').prop('disabled', false);
        $('#credential-github-renew').prop('disabled', false);
        $('#credential-modal-error').hide().text('');
        applyProviderDefaults();
        if (editingGit) $('#credential-provider').prop('disabled', true);
        if (credential.registry) $('#credential-registry').val(credential.registry);
        $('#compose-credential-modal').css('display', 'flex');
        $('#credential-name').trigger('focus');
    }

    function saveCredential() {
        var $button = $('.credential-save').prop('disabled', true);
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', {
            action: 'saveCredential', id: $('#credential-id').val(), name: $('#credential-name').val(),
            provider: $('#credential-provider').val(), registry: $('#credential-registry').val(),
            username: $('#credential-username').val(), secret: $('#credential-secret').val()
        }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            if (response.result !== 'success') {
                $('#credential-modal-error').text(response.message || '无法保存凭据。').show();
                return;
            }
            closeModal();
            loadCredentials(function() { if (onSaved) onSaved(response.credential); });
        }).fail(function() {
            $('#credential-modal-error').text('无法连接凭据服务。').show();
        }).always(function() { $button.prop('disabled', false); });
    }

    function loadCredentials(callback) {
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'listCredentials' }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            credentials = response.result === 'success' ? (response.credentials || []) : [];
            renderTable();
            $(document).trigger('compose:credentials-loaded', [credentials]);
            if (callback) callback(credentials);
        });
    }

    function renderTable() {
        var $body = $('#credentials-tbody');
        if (!$body.length) return;
        $body.empty();
        if (!credentials.length) {
            $body.append('<tr><td colspan="6" class="credential-empty">尚未保存任何凭据。</td></tr>');
            return;
        }
        credentials.forEach(function(credential) {
            var $row = $('<tr>');
            var providerCfg = PROVIDER_CONFIGS[credential.provider];
            var providerLabel = providerCfg ? providerCfg.label : (credential.provider || 'generic');
            if (credential.authMethod === 'oauth_device') {
                providerLabel += ' (OAuth)';
            }
            $row.append($('<td>').text(credential.name));
            $row.append($('<td>').text(providerLabel));
            $row.append($('<td>').text(credential.registry));
            $row.append($('<td>').text(credential.username));
            $row.append($('<td>').text((credential.stacks || []).join(', ') || '未分配'));
            var $actions = $('<td class="credential-list-actions">');
            $('<button type="button" title="测试凭据"><i class="fa fa-plug"></i></button>').on('click', function() { testCredential(credential, $(this)); }).appendTo($actions);
            // A git stack's deploy key is a key pair compose-git made; there is nothing to edit.
            if (credential.provider !== 'git-ssh') {
                $('<button type="button" title="Edit"><i class="fa fa-pencil"></i></button>').on('click', function() { openModal(credential); }).appendTo($actions);
            }
            $('<button type="button" title="Delete"><i class="fa fa-trash"></i></button>').on('click', function() { deleteCredential(credential); }).appendTo($actions);
            $row.append($actions).appendTo($body);
        });
    }

    function testCredential(credential, $button) {
        var $icon = $button.prop('disabled', true).find('i').removeClass('fa-plug').addClass('fa-spinner fa-spin');
        $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'testCredential', id: credential.id }).done(function(data) {
            var response;
            try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
            if (response.result !== 'success') {
                swal({ title: '无法测试凭据', text: response.message || '未知错误。', type: 'error' });
                return;
            }
            if (response.valid) {
                swal({ title: '凭据有效', text: response.message || (credential.name + ' authenticated successfully.'), type: 'success' });
            } else if (credential.provider === 'git-ssh') {
                // A deploy key cannot be edited (the modal has no form for it), and its
                // message already says what failed: a key not added yet, a changed host key.
                swal({ title: '凭据被拒绝', text: response.message || '仓库拒绝了这个部署密钥。', type: 'warning' });
            } else if (credential.provider === 'git') {
                swal({
                    title: '凭据被拒绝',
                    text: response.message || '仓库拒绝了这个令牌。',
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonText: '编辑凭据',
                    cancelButtonText: 'Later'
                }, function(confirmed) {
                    if (!confirmed) return;
                    openModal(credential, null);
                    $('#credential-secret').trigger('focus');
                });
            } else {
                var isOAuth = credential.authMethod === 'oauth_device';
                swal({
                    title: '凭据被拒绝',
                    text: (response.message || '镜像仓库拒绝了这个凭据。') + ' 它可能已过期或被吊销。',
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonText: isOAuth ? '续期授权' : '编辑凭据',
                    cancelButtonText: 'Later'
                }, function(confirmed) {
                    if (!confirmed) return;
                    openModal(credential, null);
                    if (isOAuth) {
                        startGitHubSignIn(true);
                    } else {
                        $('#credential-secret').trigger('focus');
                    }
                });
            }
        }).fail(function() {
            swal({ title: '无法测试凭据', text: '无法连接凭据服务。', type: 'error' });
        }).always(function() {
            $button.prop('disabled', false);
            $icon.removeClass('fa-spinner fa-spin').addClass('fa-plug');
        });
    }

    function deleteCredential(credential) {
        if ((credential.stacks || []).length) {
            swal({ title: '凭据正在使用中', text: '请先从以下位置移除：' + credential.stacks.join(', '), type: 'warning' });
            return;
        }
        swal({ title: '删除凭据？', text: credential.name, type: 'warning', showCancelButton: true }, function(confirmed) {
            if (!confirmed) return;
            $.post(window.caURL || '/plugins/compose.manager/include/Exec.php', { action: 'deleteCredential', id: credential.id }).done(function(data) {
                var response;
                try { response = typeof data === 'string' ? JSON.parse(data) : data; } catch (error) { response = {}; }
                if (response.result !== 'success') {
                    showDeleteError(response.message || '未知错误。');
                }
                loadCredentials();
            }).fail(function() {
                showDeleteError('无法连接凭据服务。');
                loadCredentials();
            });
        });
    }

    function showDeleteError(message) {
        // Wait for the confirm dialog to finish closing, or SweetAlert can hide this one with it.
        setTimeout(function() {
            swal({ title: '无法删除凭据', text: message, type: 'error' });
        }, 300);
    }

    function populateSelect($select, selectedId) {
        $select.empty().append($('<option value="">').text('匿名或无凭据'));
        credentials.forEach(function(credential) {
            // Git repository credentials are not registry logins; git stacks choose theirs elsewhere.
            if (credential.provider === 'git' || credential.provider === 'git-ssh') return;
            $select.append($('<option>').val(credential.id).text(credential.name + ' (' + credential.registry + ' / ' + credential.username + ')'));
        });
        $select.val(selectedId || '');
        if (selectedId && $select.val() !== selectedId) {
            $select.append($('<option>').val(selectedId).text('缺少凭据').prop('disabled', true)).val(selectedId);
        }
    }

    window.ComposeCredentialManager = { open: openModal, load: loadCredentials, populateSelect: populateSelect };
    $(function() {
        ensureModal();
        $('#add-credential-button').on('click', function() { openModal(); });
    });
})(window, jQuery);