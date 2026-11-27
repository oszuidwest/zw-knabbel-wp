/**
 * Radio News row in the block editor's Summary panel, and the last Babbel sync
 * error under it and as an editor notice. Strings come from
 * includes/admin/block-editor.php.
 */
(({ components, coreData, data, editor, element, plugins }) => {
    const { createElement: el, Fragment, useEffect } = element;
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
        // error stored after that save, without a reload.
        const [syncWarning] = coreData.useEntityProp(
            'postType',
            'post',
            'knabbel_sync_warning',
        );
        const { createWarningNotice, removeNotice } =
            data.useDispatch('core/notices');
        const enabled = Boolean(meta?.[META_KEY]);
        const message = syncWarning?.message;
        // Some messages say what to do instead, so the server leaves out the hint.
        const retry = syncWarning?.retry ? text.syncRetry : '';

        // The sidebar can be closed or covered by the post-publish panel, so
        // the error also shows at the top of the editor.
        useEffect(() => {
            if (message) {
                // The hint is a new sentence, so the message gets a full stop.
                const content = retry
                    ? `${message.replace(/[^.!?]$/, '$&.')} ${retry}`
                    : message;
                // Not dismissible: a repeat of the same error would not bring it back.
                createWarningNotice(`${text.syncWarningTitle}: ${content}`, {
                    id: NOTICE_ID,
                    isDismissible: false,
                });
            } else {
                removeNotice(NOTICE_ID);
            }
        }, [message, retry, createWarningNotice, removeNotice]);

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
                        },
                        el('strong', null, text.syncWarningTitle),
                        el('div', null, message),
                        retry && el('div', null, retry),
                    ),
                ),
        );
    }

    plugins.registerPlugin('zw-knabbel-wp-radio-news', { render: RadioNews });
})(window.wp);
