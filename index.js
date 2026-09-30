const DRAWER_CLASS = "k-superstructure-drawer";

panel.plugin("jamie-humm/kirby-super-structure", {
  fields: {
    // registers the `superstructure` field type in the Panel
    // (rendered as k-superstructure-field, behaves like the core structure field)
    superstructure: {
      extends: "k-structure-field",
      props: {
        appearance: {
          type: String,
          default: "pages",
        },
      },
      watch: {
        appearance() {
          this.applyAppearance();
        },
      },
      mounted() {
        this.applyAppearance();
      },
      methods: {
        open(...args) {
          const drawer = this.$panel.drawer;
          const original = drawer.open;
          // core's own open() method, resolved from the extended component
          const parentOpen = this.$options.extends.options.methods.open;

          // tag the drawer this field opens (only for the duration of this call)
          drawer.open = (options, ...rest) => {
            options.props = { ...options.props, size: "superstructure" };
            return original.call(drawer, options, ...rest);
          };

          try {
            return parentOpen.apply(this, args);
          } finally {
            drawer.open = original;
          }
        },
          applyAppearance() {
          // the component's root element is the k-field wrapper that also
          // carries .k-field-type-superstructure
          this.$el.dataset.appearance = this.appearance;
        },
      },
    },
  },
});
