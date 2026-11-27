/**
 * Radio News row in the block editor's Summary panel, and the last Babbel sync
 * error under it and as an editor notice. Strings come from
 * includes/admin/block-editor.php.
 */
(({ components, coreData, data, editor, element, plugins }) => {
    const { createElement: el, Fragment, useEffect, useMemo } = element;
    const text = window.knabbelBlockEditor;
    const META_KEY = '_zw_knabbel_send_to_babbel';
    const NOTICE_ID = 'knabbel-sync-warning';

    function RadioNews() {
        const [meta, setMeta] = coreData.useEntityProp(
            'postType',
            'post',
            'meta',
        );
        // Every save response carries this field, so the warning shows the
        // error stored after that save, without a reload. While a retry is on
        // its way the field is empty; a failed retry shows after a reload.
        const [syncWarning] = coreData.useEntityProp(
            'postType',
            'post',
            'knabbel_sync_error',
        );
        const { createWarningNotice, removeNotice } =
            data.useDispatch('core/notices');
        const { savePost } = data.useDispatch('core/editor');
        const enabled = Boolean(meta?.[META_KEY]);
        const message = syncWarning?.message;
        // False when saving again would fail the same way; the message then
        // says what to do instead.
        const canRetry = Boolean(syncWarning?.retry);
        // A published post only saves after a change; savePost() does not need one.
        const actions = useMemo(
            () =>
                canRetry
                    ? [{ label: text.tryAgain, onClick: () => savePost() }]
                    : [],
            [canRetry, savePost],
        );

        // The sidebar can be closed or covered by the post-publish panel, so
        // the error also shows at the top of the editor.
        useEffect(() => {
            if (message) {
                // Not dismissible: a repeat of the same error would not bring it back.
                createWarningNotice(`${text.syncWarningTitle}: ${message}`, {
                    id: NOTICE_ID,
                    isDismissible: false,
                    actions,
                });
            } else {
                removeNotice(NOTICE_ID);
            }
        }, [message, actions, createWarningNotice, removeNotice]);

        return el(
            Fragment,
            null,
            // Same row markup as core's Summary rows (Status, Slug).
            el(
                editor.PluginPostStatusInfo,
                { className: 'knabbel-post-status' },
                el(
                    'div',
                    { className: 'editor-post-panel__row' },
                    el(
                        'div',
                        { className: 'editor-post-panel__row-label' },
                        text.label,
                    ),
                    el(
                        'div',
                        { className: 'editor-post-panel__row-control' },
                        el(
                            components.Button,
                            {
                                size: 'compact',
                                variant: 'tertiary',
                                // A toggle button: screen readers announce the
                                // name and whether it is pressed.
                                'aria-label': text.label,
                                'aria-pressed': enabled,
                                onClick: () =>
                                    setMeta({ [META_KEY]: !enabled }),
                            },
                            enabled ? text.yes : text.no,
                        ),
                    ),
                ),
            ),
            message &&
                el(
                    editor.PluginPostStatusInfo,
                    { className: 'knabbel-post-status' },
                    el(
                        components.Notice,
                        {
                            className: 'knabbel-sync-notice',
                            status: 'warning',
                            isDismissible: false,
                            actions,
                        },
                        el('strong', null, text.syncWarningTitle),
                        el('div', null, message),
                    ),
                ),
        );
    }

    plugins.registerPlugin('zw-knabbel-wp-radio-news', { render: RadioNews });
})(window.wp);
