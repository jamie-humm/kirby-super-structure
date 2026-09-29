panel.plugin("jamie-humm/kirby-super-structure", {
  fields: {
    // registers the `superstructure` field type in the Panel
    // (rendered as k-superstructure-field, behaves like the core structure field)
    superstructure: {
      extends: "k-structure-field",
    },
  },
});
