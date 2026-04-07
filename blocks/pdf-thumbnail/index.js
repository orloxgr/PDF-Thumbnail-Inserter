(function (wp) {
    const el = wp.element.createElement;
    const __ = wp.i18n.__;
    const registerBlockType = wp.blocks.registerBlockType;
    const InspectorControls = wp.blockEditor.InspectorControls;
    const useBlockProps = wp.blockEditor.useBlockProps;
    const MediaUpload = wp.blockEditor.MediaUpload;
    const MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    const PanelBody = wp.components.PanelBody;
    const Button = wp.components.Button;
    const TextControl = wp.components.TextControl;
    const SelectControl = wp.components.SelectControl;
    const ToggleControl = wp.components.ToggleControl;
    const Notice = wp.components.Notice;
    const RangeControl = wp.components.RangeControl;
    const ServerSideRender = wp.serverSideRender;

    function sanitizeIds(value) {
        const ids = Array.isArray(value) ? value : [];
        return ids
            .map(function (id) { return parseInt(id, 10); })
            .filter(function (id) { return !isNaN(id) && id > 0; })
            .filter(function (id, index, arr) { return arr.indexOf(id) === index; });
    }

    function getSelectedIds(attributes) {
        const ids = sanitizeIds(attributes.ids);
        if (ids.length) {
            return ids;
        }
        return attributes.id ? [parseInt(attributes.id, 10)] : [];
    }

    registerBlockType('pdf-thumbnail-inserter/pdf-thumbnail', {
        edit: function (props) {
            const attributes = props.attributes;
            const setAttributes = props.setAttributes;
            const selectedIds = getSelectedIds(attributes);
            const hasPdf = selectedIds.length > 0;
            const isSingle = selectedIds.length === 1;
            const blockProps = useBlockProps({ className: 'pti-block-editor-wrap' });

            function setBoolAttr(key, value) {
                setAttributes({ [key]: value ? 'yes' : 'no' });
            }

            function onSelectPdf(media) {
                const items = Array.isArray(media) ? media : [media];
                const ids = sanitizeIds(items.map(function (item) {
                    return item && item.id ? item.id : 0;
                }));

                setAttributes({
                    ids: ids,
                    id: ids[0] || 0,
                    title: ids.length === 1 && items[0] && items[0].title ? (attributes.title || items[0].title) : ''
                });
            }

            function removePdf() {
                setAttributes({ id: 0, ids: [], title: '', thumbnail: '', url: '' });
            }

            const inspector = el(
                InspectorControls,
                {},
                el(
                    PanelBody,
                    { title: __('PDF settings', 'pdf-thumbnail-inserter'), initialOpen: true },
                    el(MediaUploadCheck, {},
                        el(MediaUpload, {
                            onSelect: onSelectPdf,
                            allowedTypes: ['application/pdf'],
                            multiple: true,
                            gallery: false,
                            value: selectedIds,
                            render: function (obj) {
                                return el(Button, { variant: 'secondary', onClick: obj.open }, hasPdf ? __('Replace PDFs', 'pdf-thumbnail-inserter') : __('Select PDFs', 'pdf-thumbnail-inserter'));
                            }
                        })
                    ),
                    hasPdf ? el(Button, { isDestructive: true, variant: 'link', onClick: removePdf }, __('Remove selection', 'pdf-thumbnail-inserter')) : null,
                    el(RangeControl, {
                        label: __('Desktop columns', 'pdf-thumbnail-inserter'),
                        value: attributes.columns_desktop || 3,
                        min: 1,
                        max: 6,
                        onChange: function (value) { setAttributes({ columns_desktop: value || 3 }); }
                    }),
                    el(RangeControl, {
                        label: __('Laptop / tablet columns', 'pdf-thumbnail-inserter'),
                        value: attributes.columns_laptop || 2,
                        min: 1,
                        max: 6,
                        onChange: function (value) { setAttributes({ columns_laptop: value || 2 }); }
                    }),
                    el(RangeControl, {
                        label: __('Mobile columns', 'pdf-thumbnail-inserter'),
                        value: attributes.columns_mobile || 1,
                        min: 1,
                        max: 6,
                        onChange: function (value) { setAttributes({ columns_mobile: value || 1 }); }
                    }),
                    el(SelectControl, {
                        label: __('Image size', 'pdf-thumbnail-inserter'),
                        value: attributes.size || 'medium',
                        options: [
                            { label: __('Thumbnail', 'pdf-thumbnail-inserter'), value: 'thumbnail' },
                            { label: __('Medium', 'pdf-thumbnail-inserter'), value: 'medium' },
                            { label: __('Large', 'pdf-thumbnail-inserter'), value: 'large' },
                            { label: __('Full', 'pdf-thumbnail-inserter'), value: 'full' }
                        ],
                        onChange: function (value) { setAttributes({ size: value }); }
                    }),
                    el(SelectControl, {
                        label: __('Link target', 'pdf-thumbnail-inserter'),
                        value: attributes.link_to || 'file',
                        options: [
                            { label: __('PDF file', 'pdf-thumbnail-inserter'), value: 'file' },
                            { label: __('Attachment page', 'pdf-thumbnail-inserter'), value: 'attachment' },
                            { label: __('Custom URL', 'pdf-thumbnail-inserter'), value: 'custom' },
                            { label: __('No link', 'pdf-thumbnail-inserter'), value: 'none' }
                        ],
                        onChange: function (value) { setAttributes({ link_to: value }); }
                    }),
                    isSingle && attributes.link_to === 'custom' ? el(TextControl, {
                        label: __('Custom URL', 'pdf-thumbnail-inserter'),
                        value: attributes.url || '',
                        onChange: function (value) { setAttributes({ url: value }); }
                    }) : null,
                    isSingle ? el(TextControl, {
                        label: __('Override title', 'pdf-thumbnail-inserter'),
                        value: attributes.title || '',
                        onChange: function (value) { setAttributes({ title: value }); }
                    }) : null,
                    !isSingle && hasPdf ? el(Notice, { status: 'warning', isDismissible: false }, __('Title, custom URL, and custom thumbnail overrides apply only when a single PDF is selected.', 'pdf-thumbnail-inserter')) : null,
                    el(TextControl, {
                        label: __('Override button text', 'pdf-thumbnail-inserter'),
                        value: attributes.button_text || '',
                        onChange: function (value) { setAttributes({ button_text: value }); }
                    }),
                    el(ToggleControl, {
                        label: __('Show title', 'pdf-thumbnail-inserter'),
                        checked: (attributes.show_title || 'yes') === 'yes',
                        onChange: function (value) { setBoolAttr('show_title', value); }
                    }),
                    el(ToggleControl, {
                        label: __('Show button', 'pdf-thumbnail-inserter'),
                        checked: (attributes.show_button || 'yes') === 'yes',
                        onChange: function (value) { setBoolAttr('show_button', value); }
                    }),
                    el(ToggleControl, {
                        label: __('Open in new tab', 'pdf-thumbnail-inserter'),
                        checked: (attributes.new_tab || 'yes') === 'yes',
                        onChange: function (value) { setBoolAttr('new_tab', value); }
                    })
                )
            );

            if (!hasPdf) {
                return el(
                    'div',
                    blockProps,
                    inspector,
                    el('div', { className: 'pti-block-placeholder' },
                        el('p', {}, __('Choose one or more PDFs and the block will render them in a responsive grid.', 'pdf-thumbnail-inserter')),
                        el(MediaUploadCheck, {},
                            el(MediaUpload, {
                                onSelect: onSelectPdf,
                                allowedTypes: ['application/pdf'],
                                multiple: true,
                                gallery: false,
                                render: function (obj) {
                                    return el(Button, { variant: 'primary', onClick: obj.open }, __('Select PDFs', 'pdf-thumbnail-inserter'));
                                }
                            })
                        )
                    )
                );
            }

            return el(
                'div',
                blockProps,
                inspector,
                el(Notice, { status: 'info', isDismissible: false }, __('This block renders dynamically on the server and uses the generated or existing PDF previews. Click the block border to select it; preview links are disabled inside the editor.', 'pdf-thumbnail-inserter')),
                el('div', { className: 'pti-block-editor-preview', 'aria-hidden': 'true' },
                    el(ServerSideRender, {
                        block: 'pdf-thumbnail-inserter/pdf-thumbnail',
                        attributes: attributes
                    })
                )
            );
        },
        save: function () {
            return null;
        }
    });
}(window.wp));
