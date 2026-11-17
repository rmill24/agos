# AGOS design system

P2-T00 establishes a calm, professional fintech interface for Philippine MSMEs. The visual language uses deep navy and blue for trust and primary actions, neutral canvas and white surfaces for clarity, and explicit green, amber, and red states for operational feedback. Status is always communicated with text or icons as well as color.

## Tokens

| Area | AGOS token / choice |
| --- | --- |
| Brand | `brand-600 #1d4ed8`, `brand-700 #1e40af`, `brand-900 #172554` |
| Canvas | `canvas #f8fafc` |
| Ink | `ink #0f172a`; supporting slate text for hierarchy |
| Surface | white cards with `slate-200` borders |
| Success | emerald, with a label such as “Organized” or “Complete” |
| Warning | amber, with an explanatory label |
| Risk / error | red, with an explanatory label |
| Typography | Instrument Sans, system sans fallback; bold headings and readable body copy |
| Radius | `rounded-xl` controls and `rounded-2xl` cards |
| Elevation | subtle `shadow-sm`; stronger shadow only for hero emphasis |
| Focus | visible 2px, offset brand outline for keyboard users |
| Spacing | Tailwind 4 spacing scale; page containers use responsive `px-4/6/8` and `py-6/8` |

## Shared patterns

`agos-page`, `agos-page-header`, `agos-card`, `agos-card-muted`, `agos-eyebrow`, `agos-link`, and `agos-nav-label` are reusable CSS component tokens in `resources/css/app.css`. Flux UI remains the component layer for fields, buttons, navigation, menus, dialogs, toasts, and icons. The authenticated shell exposes only implemented financial modules and keeps settings in a separate navigation group.

The landing page intentionally describes only the current AGOS workspace: business onboarding, team access, categories, and income/expense transactions. Forecasting, scenarios, invoices, imports, integrations, and recommendations are not presented as available features.
