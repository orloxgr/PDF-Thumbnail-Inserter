jQuery(function ($) {
    function askColumn(label, fallback) {
        const value = window.prompt(label, String(fallback));

        if (value === null) {
            return null;
        }

        const parsed = parseInt(value, 10);
        if (isNaN(parsed) || parsed < 1 || parsed > 6) {
            return fallback;
        }

        return parsed;
    }

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

            const desktop = askColumn(pdfThumbnailClassic.labels.desktopColumns, 3);
            if (desktop === null) {
                return;
            }

            const laptop = askColumn(pdfThumbnailClassic.labels.laptopColumns, 2);
            if (laptop === null) {
                return;
            }

            const mobile = askColumn(pdfThumbnailClassic.labels.mobileColumns, 1);
            if (mobile === null) {
                return;
            }

            const shortcode = ids.length === 1
                ? '[pdf_thumbnail id="' + ids[0] + '" columns_desktop="' + desktop + '" columns_laptop="' + laptop + '" columns_mobile="' + mobile + '"]'
                : '[pdf_thumbnail ids="' + ids.join(',') + '" columns_desktop="' + desktop + '" columns_laptop="' + laptop + '" columns_mobile="' + mobile + '"]';

            wp.media.editor.insert(shortcode);
        });

        frame.open();
    });
});
