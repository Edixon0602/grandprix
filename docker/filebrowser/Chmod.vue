<template>
  <div class="card floating">
    <div class="card-title">
      <h2>{{ $t("prompts.permissions") || "Permisos" }}</h2>
    </div>

    <div class="card-content">
      <p style="margin-bottom: 12px; font-weight: 500;">
        {{ itemName }}
      </p>

      <div class="chmod-grid">
        <div class="chmod-header">
          <span></span>
          <span>Lectura (4)</span>
          <span>Escritura (2)</span>
          <span>Ejecución (1)</span>
        </div>
        <div class="chmod-row">
          <span class="chmod-label">Propietario</span>
          <input type="checkbox" v-model="perms.ur" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.uw" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.ux" @change="updateOctalFromCheckboxes" />
        </div>
        <div class="chmod-row">
          <span class="chmod-label">Grupo</span>
          <input type="checkbox" v-model="perms.gr" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.gw" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.gx" @change="updateOctalFromCheckboxes" />
        </div>
        <div class="chmod-row">
          <span class="chmod-label">Otros</span>
          <input type="checkbox" v-model="perms.or" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.ow" @change="updateOctalFromCheckboxes" />
          <input type="checkbox" v-model="perms.ox" @change="updateOctalFromCheckboxes" />
        </div>
      </div>

      <div class="chmod-octal-wrap" style="margin-top: 16px; display: flex; align-items: center; gap: 10px;">
        <label style="font-weight: bold;">Permisos numéricos (octal):</label>
        <input
          type="text"
          class="input"
          style="width: 100px; text-align: center; font-family: monospace; font-size: 16px;"
          v-model="octal"
          maxlength="4"
          @input="updateCheckboxesFromOctal"
        />
      </div>

      <div v-if="isDir" style="margin-top: 14px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="checkbox" v-model="recursive" />
          <span>Aplicar recursivamente a subdirectorios y archivos</span>
        </label>
      </div>
    </div>

    <div class="card-action">
      <button
        class="button button--flat button--grey"
        @click="closeHovers"
        type="button"
      >
        {{ $t("buttons.cancel") || "Cancelar" }}
      </button>
      <button
        @click="submit"
        class="button button--flat"
        type="submit"
        :disabled="loading"
      >
        {{ loading ? "Guardando..." : ($t("buttons.save") || "Guardar") }}
      </button>
    </div>
  </div>
</template>

<script>
import { mapActions, mapState, mapWritableState } from "pinia";
import { useFileStore } from "@/stores/file";
import { useLayoutStore } from "@/stores/layout";
import { files as api } from "@/api";

export default {
  name: "chmod",
  inject: ["$showError", "$showSuccess"],
  data() {
    return {
      perms: {
        ur: true, uw: true, ux: false,
        gr: true, gw: false, gx: false,
        or: true, ow: false, ox: false,
      },
      octal: "0644",
      recursive: false,
      loading: false,
    };
  },
  computed: {
    ...mapState(useFileStore, ["req", "selected", "selectedCount", "isListing"]),
    ...mapWritableState(useFileStore, ["reload"]),
    item() {
      if (!this.isListing) return this.req;
      if (this.selectedCount === 0) return this.req;
      return this.req.items[this.selected[0]];
    },
    itemName() {
      return this.item?.name || "";
    },
    isDir() {
      return !!this.item?.isDir;
    },
  },
  created() {
    if (this.item && this.item.mode !== undefined) {
      const mode = this.item.mode & 0o777;
      this.octal = "0" + mode.toString(8).padStart(3, "0");
      this.updateCheckboxesFromOctal();
    } else if (this.isDir) {
      this.octal = "0755";
      this.updateCheckboxesFromOctal();
    }
  },
  methods: {
    ...mapActions(useLayoutStore, ["closeHovers"]),
    updateOctalFromCheckboxes() {
      const u = (this.perms.ur ? 4 : 0) + (this.perms.uw ? 2 : 0) + (this.perms.ux ? 1 : 0);
      const g = (this.perms.gr ? 4 : 0) + (this.perms.gw ? 2 : 0) + (this.perms.gx ? 1 : 0);
      const o = (this.perms.or ? 4 : 0) + (this.perms.ow ? 2 : 0) + (this.perms.ox ? 1 : 0);
      this.octal = `0${u}${g}${o}`;
    },
    updateCheckboxesFromOctal() {
      const clean = this.octal.replace(/^0+/, "") || "0";
      const val = parseInt(clean, 8);
      if (isNaN(val)) return;
      const u = (val >> 6) & 7;
      const g = (val >> 3) & 7;
      const o = val & 7;
      this.perms.ur = !!(u & 4);
      this.perms.uw = !!(u & 2);
      this.perms.ux = !!(u & 1);
      this.perms.gr = !!(g & 4);
      this.perms.gw = !!(g & 2);
      this.perms.gx = !!(g & 1);
      this.perms.or = !!(o & 4);
      this.perms.ow = !!(o & 2);
      this.perms.ox = !!(o & 1);
    },
    async submit() {
      this.loading = true;
      try {
        const itemUrl = this.item.url;
        await api.chmod(itemUrl, this.octal, this.recursive);
        this.reload = true;
        this.closeHovers();
      } catch (e) {
        this.$showError(e);
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
.chmod-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: rgba(0, 0, 0, 0.04);
  padding: 12px;
  border-radius: 8px;
  margin-top: 8px;
}
.chmod-header, .chmod-row {
  display: grid;
  grid-template-columns: 120px 1fr 1fr 1fr;
  align-items: center;
  text-align: center;
}
.chmod-header {
  font-size: 12px;
  font-weight: 600;
  opacity: 0.8;
  padding-bottom: 6px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}
.chmod-label {
  text-align: left;
  font-weight: 500;
  font-size: 14px;
}
.chmod-row input[type="checkbox"] {
  width: 18px;
  height: 18px;
  cursor: pointer;
  margin: 0 auto;
}
</style>
