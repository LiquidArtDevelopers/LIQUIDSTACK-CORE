const DEFAULT_LANG = import.meta.env.LANG_DEFAULT || "es";
const HANDLERS_KEY = "__liquidModuleFormContactHandlers";

const getCurrentLanguage = () => {
  const documentLanguage = document.documentElement.lang
    ?.trim()
    .toLowerCase()
    .slice(0, 2);

  if (/^[a-z]{2}$/.test(documentLanguage ?? "")) {
    return documentLanguage;
  }

  const routeLanguage = window.location.pathname
    .split("/")
    .filter(Boolean)[0]
    ?.toLowerCase();

  return /^[a-z]{2}$/.test(routeLanguage ?? "")
    ? routeLanguage
    : DEFAULT_LANG;
};

const findError = (root, field) =>
  Array.from(root.querySelectorAll("[data-form-error]")).find(
    (element) => element.dataset.formError === field,
  ) ?? null;

const clearErrors = (root) => {
  root.querySelectorAll("[data-form-error]").forEach((element) => {
    element.textContent = "";
    const fieldRoot = element.closest(
      ".moduleFormContact-field, .moduleFormContact-captcha, .moduleFormContact-terms",
    );
    fieldRoot?.querySelector("input, textarea")?.removeAttribute("aria-invalid");
  });
};

const showError = (root, field, message) => {
  const error = findError(root, field) ?? findError(root, "nombre_error");
  if (!error) {
    return;
  }

  error.textContent = message;

  const fieldRoot = error.closest(
    ".moduleFormContact-field, .moduleFormContact-captcha, .moduleFormContact-terms",
  );
  const control = fieldRoot?.querySelector("input, textarea");
  control?.setAttribute("aria-invalid", "true");
  control?.focus({ preventScroll: true });
};

const createCaptcha = (root) => {
  const first = root.querySelector("[data-form-captcha-a]");
  const second = root.querySelector("[data-form-captcha-b]");
  const solution = root.querySelector("[data-form-captcha-solution]");

  if (!first || !second || !solution) {
    return;
  }

  const firstNumber = Math.floor(Math.random() * 10) + 1;
  const secondNumber = Math.floor(Math.random() * 10) + 1;

  first.textContent = String(firstNumber);
  second.textContent = String(secondNumber);
  solution.value = String(firstNumber + secondNumber);
};

const setBusy = (form, busy) => {
  const submit = form.querySelector("[data-form-submit]");
  const loader = form.querySelector("[data-form-loader]");

  form.setAttribute("aria-busy", busy ? "true" : "false");
  if (submit) {
    submit.disabled = busy;
  }
  if (loader) {
    loader.hidden = !busy;
    loader.setAttribute("aria-hidden", busy ? "false" : "true");
  }
};

const parseResponse = async (response) => {
  const body = await response.text();
  if (body.trim() === "") {
    return null;
  }

  try {
    return JSON.parse(body);
  } catch {
    return null;
  }
};

const initForm = (root) => {
  const form = root.querySelector("[data-form-contact]");
  if (!form) {
    return;
  }

  const previousHandlers = form[HANDLERS_KEY];
  if (previousHandlers) {
    form.removeEventListener("submit", previousHandlers.submit);
    previousHandlers.reset?.removeEventListener(
      "click",
      previousHandlers.resetClick,
    );
  }

  const body = form.querySelector("[data-form-body]");
  const success = form.querySelector("[data-form-success]");
  const reset = form.querySelector("[data-form-reset]");
  const terms = form.querySelector("[data-form-terms]");
  const lang = form.querySelector("[data-form-lang]");

  const submitHandler = async (event) => {
    event.preventDefault();

    if (form.getAttribute("aria-busy") === "true") {
      return;
    }

    clearErrors(root);

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    if (terms && !terms.checked) {
      const termsError = findError(root, "terminos_error");
      showError(
        root,
        "terminos_error",
        termsError?.dataset.requiredMessage ?? "",
      );
      return;
    }

    if (lang) {
      lang.value = getCurrentLanguage();
    }

    setBusy(form, true);

    try {
      const response = await fetch(form.action || "/form", {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });
      const payload = await parseResponse(response);

      if (!response.ok || !payload || payload.fallo === true) {
        const field =
          typeof payload?.campo === "string" && payload.campo !== ""
            ? payload.campo
            : "nombre_error";
        const message =
          typeof payload?.mensaje === "string" && payload.mensaje !== ""
            ? payload.mensaje
            : form.dataset.serverError ?? "";

        showError(root, field, message);
        return;
      }

      if (body) {
        body.hidden = true;
      }
      if (success) {
        success.hidden = false;
        success.setAttribute("aria-hidden", "false");
        success.focus({ preventScroll: true });
      }
    } catch {
      showError(
        root,
        "nombre_error",
        form.dataset.networkError ?? form.dataset.serverError ?? "",
      );
    } finally {
      setBusy(form, false);
    }
  };

  const resetHandler = () => {
    form.reset();
    clearErrors(root);
    createCaptcha(root);

    if (lang) {
      lang.value = getCurrentLanguage();
    }
    if (success) {
      success.hidden = true;
      success.setAttribute("aria-hidden", "true");
    }
    if (body) {
      body.hidden = false;
      body.querySelector("input:not([type='hidden'])")?.focus({
        preventScroll: true,
      });
    }
  };

  form.addEventListener("submit", submitHandler);
  reset?.addEventListener("click", resetHandler);
  form[HANDLERS_KEY] = {
    submit: submitHandler,
    reset,
    resetClick: resetHandler,
  };

  if (lang) {
    lang.value = getCurrentLanguage();
  }
  createCaptcha(root);
  setBusy(form, false);
};

export default function initModuleFormContact() {
  document.querySelectorAll(".moduleFormContact").forEach(initForm);
}

