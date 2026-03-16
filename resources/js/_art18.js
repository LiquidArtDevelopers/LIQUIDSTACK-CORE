
export default function initArt18() {

    // console.clear();

    const roots = Array.from(document.querySelectorAll(".art18"));
    if (roots.length === 0) {
        return;
    }

    const initInstance = (root) => {
        const cardsContainer = root.querySelector(".cards");
        const cards = Array.from(root.querySelectorAll(".card"));
        const overlay = root.querySelector(".overlay");

        if (!cardsContainer || !overlay || cards.length === 0) {
            return;
        }

        if (overlay.children.length === cards.length) {
            return;
        }

        overlay.innerHTML = "";

        const applyOverlayMask = (e) => {
            const bounds = cardsContainer.getBoundingClientRect();
            const x = e.clientX - bounds.left;
            const y = e.clientY - bounds.top;

            overlay.style.setProperty("--opacity", "1");
            overlay.style.setProperty("--x", `${x}px`);
            overlay.style.setProperty("--y", `${y}px`);
        };

        const clearOverlayMask = () => {
            overlay.style.setProperty("--opacity", "0");
        };

        const createOverlayCta = (overlayCard, ctaEl) => {
            if (!ctaEl) {
                return;
            }
            const overlayCta = document.createElement("div");
            overlayCta.classList.add("cta");
            overlayCta.textContent = ctaEl.textContent;
            overlayCta.setAttribute("aria-hidden", true);
            overlayCard.append(overlayCta);
        };

        const observer = new ResizeObserver((entries) => {
            entries.forEach((entry) => {
                const cardIndex = cards.indexOf(entry.target);
                const boxSize = entry.borderBoxSize?.[0];
                const width = boxSize ? boxSize.inlineSize : entry.contentRect.width;
                const height = boxSize ? boxSize.blockSize : entry.contentRect.height;
                const overlayCard = overlay.children[cardIndex];

                if (cardIndex >= 0 && overlayCard) {
                    overlayCard.style.width = `${width}px`;
                    overlayCard.style.height = `${height}px`;
                }
            });
        });

        const initOverlayCard = (cardEl) => {
            const overlayCard = document.createElement("div");
            overlayCard.classList.add("card");
            createOverlayCta(overlayCard, cardEl.lastElementChild);
            overlay.append(overlayCard);
            observer.observe(cardEl);
        };

        cards.forEach(initOverlayCard);
        cardsContainer.addEventListener("pointermove", applyOverlayMask, { passive: true });
        cardsContainer.addEventListener("pointerleave", clearOverlayMask, { passive: true });
    };

    roots.forEach(initInstance);

}
