let loading;

// Listings and settings can contain no PDF canvases. Load the large library only
// when a renderer actually needs it, sharing the import between simultaneous jobs.
export function getPdfLibrary() {
    if (!loading) {
        loading = import('../pdfjs/pdf.min.js').then(library => {
            library.GlobalWorkerOptions.workerSrc = new URL('../pdfjs/pdf.worker.min.js', import.meta.url).href;
            return library;
        }).catch(error => {
            loading = null;
            throw error;
        });
    }
    return loading;
}
