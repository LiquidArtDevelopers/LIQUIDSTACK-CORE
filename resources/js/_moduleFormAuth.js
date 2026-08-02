const HANDLERS_KEY = "__liquidModuleFormAuthToggleHandlers";

const initRoot = (root) => {
  const previousHandlers = root[HANDLERS_KEY] ?? [];
  previousHandlers.forEach(({ button, handler }) => {
    button.removeEventListener("click", handler);
  });

  const handlers = [];

  root.querySelectorAll("[data-auth-password-toggle]").forEach((button) => {
    const field = button.closest(".moduleFormAuth-passwordControl");
    const input = field?.querySelector("[data-auth-password-input]");
    const toggleText = button.querySelector("[data-auth-password-toggle-text]");

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    const handler = () => {
      const willShow = input.type === "password";
      const label = willShow
        ? button.dataset.authLabelHide
        : button.dataset.authLabelShow;

      input.type = willShow ? "text" : "password";
      button.setAttribute("aria-pressed", willShow ? "true" : "false");

      if (label) {
        button.setAttribute("aria-label", label);
        if (toggleText) {
          toggleText.textContent = label;
        }
      }
    };

    button.addEventListener("click", handler);
    handlers.push({ button, handler });
  });

  root[HANDLERS_KEY] = handlers;
};

export default function initModuleFormAuth() {
  document.querySelectorAll(".moduleFormAuth").forEach(initRoot);
}
