import sys
import os
import json

def patch_backend():
    resource_path = "http/resource.go"
    with open(resource_path, "r", encoding="utf-8") as f:
        content = f.read()

    # 1. Asegurar import "strconv"
    if '"strconv"' not in content:
        content = content.replace('"strings"', '"strconv"\n\t"strings"')

    # 2. Agregar soporte para action == "chmod" en resourcePatchHandler
    target = """\t\tsrc := r.URL.Path
\t\tdst := r.URL.Query().Get("destination")
\t\taction := r.URL.Query().Get("action")
\t\tdst, err := url.QueryUnescape(dst)
\t\tdst = slashClean(dst)
\t\tsrc = slashClean(src)
\t\tif !d.Check(src) || !d.Check(dst) {
\t\t\treturn http.StatusForbidden, nil
\t\t}"""

    replacement = """\t\tsrc := r.URL.Path
\t\tdst := r.URL.Query().Get("destination")
\t\taction := r.URL.Query().Get("action")
\t\tif action == "chmod" {
\t\t\tsrc = slashClean(src)
\t\t\tif !d.Check(src) || !d.user.Perm.Modify {
\t\t\t\treturn http.StatusForbidden, nil
\t\t\t}
\t\t\tmodeStr := r.URL.Query().Get("mode")
\t\t\trecursive := r.URL.Query().Get("recursive") == "true"
\t\t\tif modeStr == "" {
\t\t\t\treturn http.StatusBadRequest, errors.New("mode is required")
\t\t\t}
\t\t\tmodeInt, err := strconv.ParseUint(modeStr, 8, 32)
\t\t\tif err != nil {
\t\t\t\treturn http.StatusBadRequest, err
\t\t\t}
\t\t\tfileMode := os.FileMode(modeInt)
\t\t\terr = d.RunHook(func() error {
\t\t\t\tif recursive {
\t\t\t\t\treturn afero.Walk(d.user.Fs, src, func(fPath string, _ os.FileInfo, err error) error {
\t\t\t\t\t\tif err != nil {
\t\t\t\t\t\t\treturn err
\t\t\t\t\t\t}
\t\t\t\t\t\treturn d.user.Fs.Chmod(fPath, fileMode)
\t\t\t\t\t})
\t\t\t\t}
\t\t\t\treturn d.user.Fs.Chmod(src, fileMode)
\t\t\t}, action, src, "", d.user)
\t\t\treturn errToStatus(err), err
\t\t}
\t\tdst, err := url.QueryUnescape(dst)
\t\tdst = slashClean(dst)
\t\tsrc = slashClean(src)
\t\tif !d.Check(src) || !d.Check(dst) {
\t\t\treturn http.StatusForbidden, nil
\t\t}"""

    if target in content:
        content = content.replace(target, replacement, 1)
        with open(resource_path, "w", encoding="utf-8") as f:
            f.write(content)
        print("Backend resource.go parchado exitosamente.")
    else:
        print("WARN: No se pudo localizar el bloque objetivo en resource.go")

def patch_frontend():
    # 1. Copiar Chmod.vue
    os.system("cp /patch/Chmod.vue frontend/src/components/prompts/Chmod.vue")

    # 2. Prompts.vue
    prompts_path = "frontend/src/components/prompts/Prompts.vue"
    with open(prompts_path, "r", encoding="utf-8") as f:
        prompts = f.read()
    if 'import Chmod from "./Chmod.vue";' not in prompts:
        prompts = prompts.replace('import Info from "./Info.vue";', 'import Info from "./Info.vue";\nimport Chmod from "./Chmod.vue";')
        prompts = prompts.replace('["info", Info],', '["info", Info],\n  ["chmod", Chmod],')
        with open(prompts_path, "w", encoding="utf-8") as f:
            f.write(prompts)
        print("Prompts.vue parchado.")

    # 3. FileListing.vue (Agregar acción de permisos en ContextMenu)
    listing_path = "frontend/src/views/files/FileListing.vue"
    with open(listing_path, "r", encoding="utf-8") as f:
        listing = f.read()
    target_action = '<action icon="info" :label="t(\'buttons.info\')" show="info" />'
    chmod_action = '<action v-if="user.perm.modify" icon="lock" :label="t(\'buttons.permissions\')" show="chmod" />\n          <action icon="info" :label="t(\'buttons.info\')" show="info" />'
    if target_action in listing and 'show="chmod"' not in listing:
        listing = listing.replace(target_action, chmod_action)
        with open(listing_path, "w", encoding="utf-8") as f:
            f.write(listing)
        print("FileListing.vue parchado con menú contextual.")

    # 4. api/files.ts
    files_api_path = "frontend/src/api/files.ts"
    with open(files_api_path, "r", encoding="utf-8") as f:
        files_api = f.read()
    if "export async function chmod" not in files_api:
        chmod_fn = """
export async function chmod(url: string, mode: string, recursive = false) {
  url = removePrefix(url);
  return fetchURL(`/api/resources${url}?action=chmod&mode=${encodeURIComponent(mode)}&recursive=${recursive}`, {
    method: "PATCH",
  });
}
"""
        with open(files_api_path, "a", encoding="utf-8") as f:
            f.write(chmod_fn)
        print("api/files.ts parchado.")

    # 5. i18n
    for lang, label, title in [("es", "Permisos", "Cambiar permisos"), ("en", "Permissions", "Change permissions")]:
        i18n_file = f"frontend/src/i18n/{lang}.json"
        if os.path.exists(i18n_file):
            try:
                with open(i18n_file, "r", encoding="utf-8") as f:
                    data = json.load(f)
                if "buttons" in data and "permissions" not in data["buttons"]:
                    data["buttons"]["permissions"] = label
                if "prompts" in data and "permissions" not in data["prompts"]:
                    data["prompts"]["permissions"] = title
                with open(i18n_file, "w", encoding="utf-8") as f:
                    json.dump(data, f, ensure_ascii=False, indent=2)
                print(f"{i18n_file} actualizado.")
            except Exception as e:
                print(f"Error actualizando {i18n_file}: {e}")

if __name__ == "__main__":
    patch_backend()
    patch_frontend()
