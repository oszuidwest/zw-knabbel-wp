/**
 * Radio News row in the block editor's Summary panel, and the last Babbel sync
 * warning under it. Strings come from includes/admin/block-editor.php.
 */
(({ components, coreData, editor, element, plugins }) => {
    const { createElement: el } = element;
    const text = window.knabbelBlockEditor;
    const META_KEY = '_zw_knabbel_send_to_babbel';

    function RadioNews() {
        const [meta, setMeta] = coreData.useEntityProp(
            'postType',
            'post',
            'meta',
        );
        const enabled = Boolean(meta?.[META_KEY]);

        // Same row markup as core's Summary rows (Status, Slug).
        const row = el(
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
                            'aria-label': enabled ? text.turnOff : text.turnOn,
                            // Send only this key, so other meta stays untouched.
                            onClick: () => setMeta({ [META_KEY]: !enabled }),
                        },
                        enabled ? text.yes : text.no,
                    ),
                ),
            ),
        );

        if (!text.syncWarning) {
            return row;
        }

        return el(
            element.Fragment,
            null,
            row,
            el(
                editor.PluginPostStatusInfo,
                { className: 'knabbel-post-status' },
                el(
                    components.Notice,
                    { status: 'warning', isDismissible: false },
                    el('strong', null, text.syncWarning.title),
                    el('div', null, text.syncWarning.message),
                ),
            ),
        );
    }

    plugins.registerPlugin('zw-knabbel-wp-radio-news', { render: RadioNews });
})(window.wp);
