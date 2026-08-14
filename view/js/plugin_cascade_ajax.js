/**
 * Descarga/activación en cascada de plugins vía AJAX (estilo WordPress).
 */
(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    function escapeHtml(text) {
        return $('<div/>').text(text == null ? '' : String(text)).html();
    }

    function postJson(url, data) {
        return $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: data,
            timeout: 300000
        });
    }

    function runSequential(items, worker) {
        var deferred = $.Deferred();
        var index = 0;
        var results = [];

        function next() {
            if (index >= items.length) {
                deferred.resolve(results);
                return;
            }

            var current = items[index++];
            worker(current, index - 1, items.length).done(function (result) {
                results.push(result);
                next();
            }).fail(function (xhr) {
                deferred.reject(xhr, current);
            });
        }

        next();
        return deferred.promise();
    }

    var api = {
        fetchInstallPlan: function (baseUrl, pluginName, csrfToken, isPrivate) {
            return postJson(baseUrl + '&action=install_plan&ajax=1', {
                _csrf_token: csrfToken,
                plugin_name: pluginName,
                private: isPrivate ? '1' : '0'
            });
        },

        downloadPlugin: function (baseUrl, pluginName, csrfToken, isPrivate) {
            return postJson(baseUrl + '&action=download_step&ajax=1', {
                _csrf_token: csrfToken,
                plugin_name: pluginName,
                private: isPrivate ? '1' : '0'
            });
        },

        activatePlugin: function (baseUrl, targetPlugin, pluginName, csrfToken) {
            return postJson(baseUrl + '&action=activate_step&ajax=1', {
                _csrf_token: csrfToken,
                target_plugin: targetPlugin,
                plugin_name: pluginName
            });
        },

        downloadCascade: function (baseUrl, pluginName, csrfToken, isPrivate, onProgress) {
            var self = this;
            return this.fetchInstallPlan(baseUrl, pluginName, csrfToken, isPrivate).then(function (planResponse) {
                if (!planResponse || !planResponse.success) {
                    return $.Deferred().reject(planResponse).promise();
                }

                var missing = planResponse.missing || [];
                if (typeof onProgress === 'function') {
                    onProgress('plan', planResponse);
                }

                if (missing.length === 0) {
                    return $.when({ plan: planResponse, downloaded: [] });
                }

                return runSequential(missing, function (item, idx, total) {
                    if (typeof onProgress === 'function') {
                        onProgress('download', { plugin: item, index: idx, total: total });
                    }
                    return self.downloadPlugin(baseUrl, item, csrfToken, isPrivate);
                }).then(function () {
                    return { plan: planResponse, downloaded: missing };
                });
            });
        },

        activateCascade: function (baseUrl, targetPlugin, csrfToken, onProgress) {
            var self = this;
            return this.fetchInstallPlan(baseUrl, targetPlugin, csrfToken, false).then(function (planResponse) {
                if (!planResponse || !planResponse.success) {
                    return $.Deferred().reject(planResponse).promise();
                }

                var pending = planResponse.pending_activation || [];
                if (pending.length === 0) {
                    return $.when(planResponse);
                }

                return runSequential(pending, function (item, idx, total) {
                    if (typeof onProgress === 'function') {
                        onProgress('activate', { plugin: item, index: idx, total: total });
                    }
                    return self.activatePlugin(baseUrl, targetPlugin, item, csrfToken);
                }).then(function () {
                    return planResponse;
                });
            });
        },

        confirmDownloadThenActivate: function (options) {
            var baseUrl = options.baseUrl;
            var pluginName = options.pluginName;
            var csrfToken = options.csrfToken;
            var isPrivate = !!options.isPrivate;
            var onStatus = options.onStatus || function () {};
            var reloadOnSuccess = options.reloadOnSuccess !== false;

            onStatus('Consultando dependencias…');

            return this.downloadCascade(baseUrl, pluginName, csrfToken, isPrivate, function (phase, data) {
                if (phase === 'plan') {
                    var missing = data.missing || [];
                    if (missing.length === 0) {
                        onStatus('Todo está descargado.');
                        return;
                    }
                    onStatus('Descargando ' + (data.missing.length) + ' plugin(s)…');
                } else if (phase === 'download') {
                    onStatus('Descargando ' + data.plugin + ' (' + (data.index + 1) + '/' + data.total + ')…');
                }
            }).then(function (result) {
                var plan = result.plan || {};
                var pending = plan.pending_activation || [];
                var missing = plan.missing || [];

                var message = '<p>Plugins descargados correctamente.</p>';
                if (missing.length > 0) {
                    message += '<p><strong>Descargados:</strong> ' + escapeHtml(missing.join(', ')) + '</p>';
                }
                if (pending.length > 0) {
                    message += '<p><strong>¿Activar ahora?</strong> Se activarán en cascada: '
                        + escapeHtml(pending.join(' → ')) + '</p>';
                } else {
                    message += '<p>Todos los plugins del plan ya estaban activos.</p>';
                }

                return $.Deferred(function (def) {
                    if (pending.length === 0) {
                        def.resolve({ activated: false });
                        return;
                    }

                    bootbox.confirm({
                        title: '<b>Activar ' + escapeHtml(pluginName) + '</b>',
                        message: message,
                        callback: function (confirmed) {
                            if (!confirmed) {
                                def.resolve({ activated: false });
                                return;
                            }

                            onStatus('Activando plugins…');
                            api.activateCascade(baseUrl, pluginName, csrfToken, function (phase, data) {
                                if (phase === 'activate') {
                                    onStatus('Activando ' + data.plugin + ' (' + (data.index + 1) + '/' + data.total + ')…');
                                }
                            }).done(function () {
                                def.resolve({ activated: true });
                            }).fail(function (xhr) {
                                def.reject(xhr);
                            });
                        }
                    });
                }).promise();
            }).done(function () {
                if (reloadOnSuccess) {
                    window.location.reload();
                }
            }).fail(function (xhr) {
                var payload = xhr && xhr.responseJSON ? xhr.responseJSON : xhr;
                var message = (payload && payload.message)
                    ? payload.message
                    : 'No se pudo completar la operación.';
                bootbox.alert({
                    title: '<b>Error</b>',
                    message: escapeHtml(message)
                });
            });
        },

        togglePlugin: function (baseUrl, pluginName, action, csrfToken, onStatus) {
            onStatus(action === 'enable' ? 'Activando…' : 'Desactivando…');
            return postJson(baseUrl + '&action=plugin_' + action + '&ajax=1', {
                _csrf_token: csrfToken,
                plugin_name: pluginName
            });
        },

        uploadPluginZip: function (baseUrl, file, csrfToken, onStatus) {
            var formData = new FormData();
            formData.append('_csrf_token', csrfToken);
            formData.append('ajax', '1');
            formData.append('action', 'plugin_upload');
            formData.append('install', 'TRUE');
            formData.append('fplugin', file);

            onStatus('Subiendo plugin…');

            return $.ajax({
                url: baseUrl + '&ajax=1',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 300000
            });
        }
    };

    window.fsPluginCascade = api;
})(window, window.jQuery);
