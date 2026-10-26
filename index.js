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
        // false when nested in blocks/layouts etc.: behaves like a core structure
        synced: {
          type: Boolean,
          default: false,
        },
      },
      watch: {
        appearance() {
          this.applyDataset();
        },
        synced() {
          this.applyDataset();
        },
      },
      mounted() {
        this.applyDataset();
      },
      methods: {
        open(...args) {
          // core's own open() method, resolved from the extended component
          const parentOpen = this.$options.extends.options.methods.open;

          if (this.synced === false) {
            return parentOpen.apply(this, args);
          }

          const drawer = this.$panel.drawer;
          const original = drawer.open;

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
        applyDataset() {
          // the component's root element is the k-field wrapper that also
          // carries .k-field-type-superstructure
          this.$el.dataset.appearance = this.appearance;
          this.$el.dataset.synced = this.synced;
        },
        // same format as SuperStructure::newUuid() in PHP (16 hex chars)
        newUuid() {
          const bytes = crypto.getRandomValues(new Uint8Array(8));
          return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
        },
        option(option, row) {
          if (option === "duplicate") {
            this.add({
              ...this.$helper.object.clone(row),
              _id: this.$helper.uuid(),
              _uuid: this.newUuid(), // the copy gets its own identity
            });
            return;
          }

          // everything else: core behavior
          return this.$options.extends.options.methods.option.call(this, option, row);
        },
      },
    },
  },
});
