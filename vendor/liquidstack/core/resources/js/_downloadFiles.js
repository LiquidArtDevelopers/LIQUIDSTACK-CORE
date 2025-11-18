
export default function initDownloadFiles(){

    const btnsDownload = document.querySelectorAll(".btn-download");
    btnsDownload.forEach((btnDownload) => {
        btnDownload.addEventListener("click", async (e) => {
            e.preventDefault();
            const url = e.target.href;
            const response = await fetch(url);
            const blob = await response.blob();
            const fileURL = URL.createObjectURL(blob);
            window.open(fileURL, "_blank"); // Abrir en una nueva pestaña
        });
    });

}