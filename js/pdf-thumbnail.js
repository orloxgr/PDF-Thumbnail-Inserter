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
                attachment_id: attachment.id
            }, function (response) {
                if (response.success) {
                    let shortcode = `[pdf_thumbnail thumbnail="${response.data.thumbnail_url}" title="${response.data.file_title}" url="${response.data.file_url}"]`;
                    wp.media.editor.insert(shortcode);
                } else {
                    alert(response.data.message);
                }
            });
        });

        frame.open();
    });
});
