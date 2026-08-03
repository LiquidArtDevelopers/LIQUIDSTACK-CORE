const STATE_KEY = "__liquidModuleFormAuth02Cleanup";

const lengthOf = (value) => Array.from(value).length;

const passwordRules = (password, confirmation) => ({
  length: lengthOf(password) >= 8,
  lowercase: /\p{Ll}/u.test(password),
  uppercase: /\p{Lu}/u.test(password),
  number: /\p{N}/u.test(password),
  symbol: /[\p{P}\p{S}]/u.test(password),
  match: confirmation.length > 0 && password === confirmation,
});

const interpolateSummary = (template, completed, total) =>
  template
    .replace("%complete%", String(completed))
    .replace("%total%", String(total));

const initPasswordToggle = (root, listen) => {
  root.querySelectorAll("[data-auth02-password-toggle]").forEach((button) => {
    const field = button.closest(".moduleFormAuth02-passwordControl");
    const input = field?.querySelector("[data-auth02-password-input]");
    const toggleText = button.querySelector(
      "[data-auth02-password-toggle-text]",
    );

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    listen(button, "click", () => {
      const willShow = input.type === "password";
      const label = willShow
        ? button.dataset.authLabelHide
        : button.dataset.authLabelShow;

      input.type = willShow ? "text" : "password";
      button.setAttribute("aria-pressed", willShow ? "true" : "false");

      if (label) {
        button.setAttribute("aria-label", label);
        if (toggleText instanceof HTMLElement) {
          toggleText.textContent = label;
        }
      }
    });
  });
};

const resetPasswordToggles = (root) => {
  root.querySelectorAll("[data-auth02-password-toggle]").forEach((button) => {
    const field = button.closest(".moduleFormAuth02-passwordControl");
    const input = field?.querySelector("[data-auth02-password-input]");
    const toggleText = button.querySelector(
      "[data-auth02-password-toggle-text]",
    );
    const label = button.dataset.authLabelShow;

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    input.type = "password";
    button.setAttribute("aria-pressed", "false");

    if (label) {
      button.setAttribute("aria-label", label);
      if (toggleText instanceof HTMLElement) {
        toggleText.textContent = label;
      }
    }
  });
};

const initPasswordPolicy = (root, listen) => {
  if (!root.matches("[data-auth02-password-policy]")) {
    return;
  }

  const form = root.querySelector("form");
  const password = root.querySelector("[data-auth02-new-password]");
  const confirmation = root.querySelector(
    "[data-auth02-password-confirmation]",
  );
  const submit = root.querySelector("[type='submit']");
  const summary = root.querySelector("[data-auth02-requirements-summary]");
  const ruleItems = new Map(
    Array.from(root.querySelectorAll("[data-auth02-rule]"))
      .map((item) => [item.dataset.auth02Rule, item])
      .filter(([rule]) => Boolean(rule)),
  );

  if (
    !(form instanceof HTMLFormElement) ||
    !(password instanceof HTMLInputElement) ||
    !(confirmation instanceof HTMLInputElement) ||
    !(submit instanceof HTMLButtonElement)
  ) {
    return;
  }

  let passwordTouched = false;
  let confirmationTouched = false;

  const update = () => {
    const rules = passwordRules(password.value, confirmation.value);
    const entries = Object.entries(rules);
    const completed = entries.filter(([, met]) => met).length;
    const allMet = completed === entries.length;
    const passwordMet = entries
      .filter(([name]) => name !== "match")
      .every(([, met]) => met);

    entries.forEach(([name, met]) => {
      const item = ruleItems.get(name);
      if (item instanceof HTMLElement) {
        item.dataset.state = met ? "met" : "pending";
      }
    });

    password.setAttribute(
      "aria-invalid",
      passwordTouched && !passwordMet ? "true" : "false",
    );
    confirmation.setAttribute(
      "aria-invalid",
      confirmationTouched && !rules.match ? "true" : "false",
    );
    submit.disabled = !allMet;

    if (summary instanceof HTMLElement) {
      const template = allMet
        ? summary.dataset.authSummaryComplete
        : summary.dataset.authSummaryProgress;
      summary.textContent = interpolateSummary(
        template ?? "%complete%/%total%",
        completed,
        entries.length,
      );
    }

    return allMet;
  };

  listen(password, "input", () => {
    passwordTouched = true;
    update();
  });
  listen(confirmation, "input", () => {
    confirmationTouched = true;
    update();
  });
  listen(form, "submit", (event) => {
    passwordTouched = true;
    confirmationTouched = true;

    if (!update()) {
      event.preventDefault();
      const firstInvalid = password.getAttribute("aria-invalid") === "true"
        ? password
        : confirmation;
      firstInvalid.focus();
    }
  });
  listen(form, "reset", () => {
    window.setTimeout(() => {
      passwordTouched = false;
      confirmationTouched = false;
      resetPasswordToggles(root);
      update();
    }, 0);
  });

  update();
};

const initRoot = (root) => {
  if (typeof root[STATE_KEY] === "function") {
    root[STATE_KEY]();
  }

  const removers = [];
  const listen = (target, type, handler) => {
    target.addEventListener(type, handler);
    removers.push(() => target.removeEventListener(type, handler));
  };

  initPasswordToggle(root, listen);
  initPasswordPolicy(root, listen);

  root[STATE_KEY] = () => {
    removers.forEach((remove) => remove());
    delete root[STATE_KEY];
  };
};

export default function initModuleFormAuth02() {
  document.querySelectorAll(".moduleFormAuth02").forEach(initRoot);
}
