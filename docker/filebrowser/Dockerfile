# ==============================================================================
# Multi-stage Dockerfile: FileBrowser con soporte de permisos (chmod) en la UI
# ==============================================================================

# --- Etapa 1: Frontend & Patch ---
FROM node:20-alpine AS frontend-builder
WORKDIR /app

RUN apk add --no-cache git python3

# Clonar código fuente de FileBrowser
RUN git clone --depth 1 https://github.com/filebrowser/filebrowser.git /app

# Copiar parches
COPY Chmod.vue /patch/Chmod.vue
COPY apply_patch.py /patch/apply_patch.py

# Aplicar parches a backend y frontend
RUN python3 /patch/apply_patch.py

# Habilitar pnpm y compilar frontend
RUN corepack enable && corepack prepare pnpm@latest --activate
WORKDIR /app/frontend
RUN pnpm install --frozen-lockfile || pnpm install
RUN pnpm run build

# --- Etapa 2: Compilación de Backend Go ---
FROM golang:1.22-alpine AS backend-builder
WORKDIR /app

RUN apk add --no-cache git

# Copiar código fuente parcheado con los assets compilados
COPY --from=frontend-builder /app /app

# Compilar binario estático
RUN CGO_ENABLED=0 go build -ldflags="-s -w" -o /filebrowser .

# --- Etapa 3: Imagen final ligera de producción ---
FROM alpine:3.20

RUN apk add --no-cache ca-certificates mailcap tini tzdata

COPY --from=backend-builder /filebrowser /filebrowser

RUN mkdir -p /srv /database /config

VOLUME ["/srv", "/database", "/config"]

EXPOSE 80

ENTRYPOINT ["/sbin/tini", "--", "/filebrowser"]
