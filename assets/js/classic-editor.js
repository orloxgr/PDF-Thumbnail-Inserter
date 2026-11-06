jQuery(function ($) {
    $(document).on('click', '#insert-pdf-thumbnail', function (event) {
        event.preventDefault();

        const frame = wp.media({
            title: pdfThumbnailClassic.labels.selectPdf,
            library: { type: 'application/pdf' },
            multiple: true,
            button: { text: pdfThumbnailClassic.labels.selectPdf }
        });

        frame.on('select', function () {
            const selection = frame.state().get('selection').toJSON();
            const ids = selection
                .map(function (item) {
                    return item && item.id ? parseInt(item.id, 10) : 0;
                })
                .filter(function (id, index, arr) {
                    return id > 0 && arr.indexOf(id) === index;
                });

            if (!ids.length) {
                alert(pdfThumbnailClassic.labels.error);
                return;
            }

            const columns = pdfThumbnailClassic.columns || {
                columns_widescreen: 4, columns_desktop: 3, columns_laptop: 2, columns_mobile: 1
            };
            const columnAttributes = Object.entries(columns).map(function ([name, value]) {
                const count = Math.max(1, Math.min(6, parseInt(value, 10) || 1));
                return ' ' + name + '="' + count + '"';
            }).join('');
            const reference = ids.length === 1 ? 'id="' + ids[0] + '"' : 'ids="' + ids.join(',') + '"';
            const shortcode = '[pdf_thumbnail_inserter ' + reference + columnAttributes + ']';

            wp.media.editor.insert(shortcode);
        });

        frame.open();
    });
});
