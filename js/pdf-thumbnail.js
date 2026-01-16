jQuery(document).ready(function ($) {
    $('#insert-pdf-thumbnail').on('click', function () {
        let frame = wp.media({
            title: 'Select PDF File',
            library: { type: 'application/pdf' },
            multiple: false
        });

        frame.on('select', function () {
            let attachment = frame.state().get('selection').first().toJSON();

            $.post(pdfThumbnail.ajax_url, {
                action: 'fetch_pdf_data',
                attachment_id: attachment.id,
                nonce: pdfThumbnail.nonce
            }, function (response) {
                if (response && response.success) {
                    const escAttr = (v) => String(v || '').replace(/"/g, '&quot;');

                    let shortcode = `[pdf_thumbnail thumbnail="${escAttr(response.data.thumbnail_url)}" title="${escAttr(response.data.file_title)}" url="${escAttr(response.data.file_url)}"]`;
                    wp.media.editor.insert(shortcode);
                } else {
                    alert(response && response.data && response.data.message ? response.data.message : 'Error.');
                }
            });
        });

        frame.open();
    });

    // Hover colors without inline JS
    $(document).on('mouseenter', '.pdf-download-button', function () {
        const hover = $(this).attr('data-hover');
        if (hover) $(this).css('background-color', hover);
    });

    $(document).on('mouseleave', '.pdf-download-button', function () {
        const normal = $(this).attr('data-normal');
        if (normal) $(this).css('background-color', normal);
    });
});
