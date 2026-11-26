/**
 * Radio News row in the block editor's Summary panel, and the last Babbel sync
 * warning under it. Strings come from includes/admin/block-editor.php.
 */
(({ components, coreData, editor, element, plugins }) => {
    const { createElement: el, Fragment } = element;
    const text = window.knabbelBlockEditor;
    const META_KEY = '_zw_knabbel_send_to_babbel';

    function RadioNews() {
        const [meta, setMeta] = coreData.useEntityProp(
            'postType',
            'post',
            'meta',
        );
        // Comes back with every save, so it shows the result of the last one.
        const [syncWarning] = coreData.useEntityProp(
            'postType',
            'post',
            'knabbel_sync_warning',
        );
        const enabled = Boolean(meta?.[META_KEY]);
        const value = enabled ? text.yes : text.no;

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
                                // The name holds the visible value, like core's "Change status: Draft".
                                'aria-label': `${text.label}: ${value}`,
                                onClick: () =>
                                    setMeta({ [META_KEY]: !enabled }),
                            },
                            value,
                        ),
                    ),
                ),
            ),
            syncWarning &&
                el(
                    editor.PluginPostStatusInfo,
                    { className: 'knabbel-post-status' },
                    el(
                        components.Notice,
                        { status: 'warning', isDismissible: false },
                        el('strong', null, text.syncWarningTitle),
                        el('div', null, syncWarning),
                    ),
                ),
        );
    }

    plugins.registerPlugin('zw-knabbel-wp-radio-news', { render: RadioNews });
})(window.wp);
