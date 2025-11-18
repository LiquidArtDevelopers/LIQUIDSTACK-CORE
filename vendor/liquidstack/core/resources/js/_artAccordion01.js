import gsap, { Power1 } from 'gsap';

export default function initArtAccordion01(){
    // stagger items
    gsap.fromTo('.accordion-item', { autoAlpha: 0, scale: 0.9 }, { duration: 1, autoAlpha: 1, scale: 1, ease: Power1.easeInOut, stagger: 0.05 });

    // function open and close accordion items
    const accordionItems = document.querySelectorAll(".accordion-item");
    accordionItems.forEach((itemAccordion) => {
        // accordion content
        const accordionTitle = itemAccordion.querySelector(".artAccordion01-title");
        const accordionContent = itemAccordion.querySelector(".artAccordion01-content");
        const accordionArrow = itemAccordion.querySelector(".artAccordion01-arrow");

        // on click title
        itemAccordion.addEventListener("click", (event) => {
        // accordionTitle.addEventListener("click", (event) => {
            // prevent click
            event.preventDefault();

            // check if accordion item is open
            if (!itemAccordion.classList.contains("-active")) {
                // close others accordions
                const accordionItemsActive = document.querySelectorAll(".accordion-item.-active");
                accordionItemsActive.forEach((itemAccordionActive) => {
                    const accordionContent = itemAccordionActive.querySelector(".artAccordion01-content");
                    const accordionArrow = itemAccordionActive.querySelector(".artAccordion01-arrow");

                    // remove active class accordion item
                    itemAccordionActive.classList.remove("-active");

                    // close content
                    gsap.to(accordionContent, {
                        duration: 0.5,
                        height: 0,
                        display: "none",
                        autoAlpha: 0,
                        ease: "expo.inOut"
                    });

                    // rotate arrow
                    gsap.to(accordionArrow, {
                        duration: 0.5, autoAlpha: 0, y: -10, ease: "back.in", onComplete: function() {
                            gsap.set(accordionArrow, { rotation: 0 });
                        }
                    });
                    gsap.to(accordionArrow, { duration: 0.5, autoAlpha: 1, y: 0, ease: "back.out", delay: 0.5 });
                });

                // add active class accordion item
                itemAccordion.classList.add("-active");

                // open content
                gsap.set(accordionContent, { height: "auto", display: "block", autoAlpha: 1 });
                gsap.from(accordionContent, { duration: 0.5, height: 0, display: "none", autoAlpha: 0, ease: "expo.inOut" });

                // rotate arrow
                gsap.to(accordionArrow, {
                    duration: 0.5, autoAlpha: 0, y: 10, ease: "back.in", onComplete: function() {
                        gsap.set(accordionArrow, { rotation: 180 });
                    }
                });
                gsap.to(accordionArrow, { duration: 0.5, autoAlpha: 1, y: 0, ease: "back.out", delay: 0.5 });

            } else {
                // remove active class accordion item
                itemAccordion.classList.remove("-active");

                // close content
                gsap.to(accordionContent, { duration: 0.5, height: 0, display: "none", autoAlpha: 0, ease: "expo.inOut" });

                // rotate arrow
                gsap.to(accordionArrow, {
                    duration: 0.5, autoAlpha: 0, y: -10, ease: "back.in", onComplete: function() {
                        gsap.set(accordionArrow, { rotation: 0 });
                    }
                });
                gsap.to(accordionArrow, { duration: 0.5, autoAlpha: 1, y: 0, ease: "back.out", delay: 0.5 });
            }
        });
    });
}
