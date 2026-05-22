# Agentic SEO Spec — Retargeted

> **This document has been retargeted (2026-05-21).**
>
> The original specification described an agent runtime, AI reasoning layer, policy engine,
> approval queue, OAuth flows, Google API fetchers, and persistent audit storage hosted inside
> the WordPress plugin. After architectural review, that design was found to conflict with the
> boundary defined in `docs/aeo_geo_google_data_architecture.md`, which establishes the plugin
> as a read-only data provider and assigns full orchestration to the external Audit Engine.
>
> **Original content** (preserved verbatim for the Audit Engine team):
> `docs/archive/agentic-seo-plugin-spec-original.md`
>
> **Plugin-side scope** distilled from the original spec:
> `docs/plugin-v3-executor-spec.md`
>
> **Action for Audit Engine team:** Copy `agentic-seo-plugin-spec-original.md` into the Audit
> Engine repo as the foundational product spec for the agent runtime, scan orchestration, AI
> reasoning, policy engine, approval queue, and portal sync. Those capabilities belong there,
> not in this plugin.
