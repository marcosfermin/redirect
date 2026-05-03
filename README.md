# Gated

Plataforma de contenido exclusivo que desbloquea galerias de imagenes y videos cuando el usuario reproduce un video de YouTube. Ideal para creadores de contenido que quieren incentivar la visualizacion de sus videos a cambio de acceso a contenido premium.

## Como funciona

1. El administrador crea una galeria con un enlace de YouTube y sube imagenes/videos
2. Se genera un link unico (slug) para compartir
3. El visitante ve el contenido borroso hasta que reproduce el video de YouTube
4. Mientras el video se reproduce, la galeria se desbloquea en tiempo real
5. Si el usuario pausa o detiene el video, el contenido se vuelve a bloquear

## Caracteristicas

### Panel de Administracion
- Crear, editar y eliminar galerias
- Subir multiples imagenes y videos con drag & drop
- Configurar imagen de preview para redes sociales (OG tags)
- Paginacion de galerias

### Pagina Publica (watch.php)
- Reproductor de YouTube embebido via IFrame API
- Galeria con efecto blur que se desbloquea al reproducir
- Lightbox para ver contenido en pantalla completa
- Soporte para imagenes (JPG, PNG, GIF, WebP) y videos (MP4, WebM, MOV)
- Diseno responsive (mobile, tablet, desktop)
- Meta tags para compartir en redes sociales

### Analytics Dashboard
- **Estadisticas globales**: vistas totales, hoy, esta semana, este mes
- **Estadisticas por galeria**: vistas, visitantes unicos, watch time promedio y total por cada link
- **Watch time tracking**: tiempo exacto que cada usuario reproduce el video de YouTube
- **Visitantes unicos**: tracking por IP (compatible con Cloudflare)
- **Device breakdown**: porcentaje de mobile, desktop y tablet
- **Top galleries**: ranking de galerias mas vistas
- **Grafico de vistas**: chart de linea con los ultimos 7 dias
- **Actividad reciente**: feed en tiempo real de las ultimas vistas
- **Filtro por galeria**: dropdown para ver estadisticas de un link especifico
- **Logs detallados**: tabla paginada con galeria, IP, dispositivo, watch time y fecha

## Stack Tecnologico

| Componente | Tecnologia |
|---|---|
| Backend | PHP 8.2 |
| Base de datos | MySQL 8.0 |
| Servidor web | Apache (mod_rewrite) |
| Frontend | HTML5, Bootstrap 5.3, Vanilla JS |
| Charts | Chart.js |
| Video | YouTube IFrame API |
| Deploy | Docker & Docker Compose |

## Requisitos

- Docker y Docker Compose

## Instalacion

### 1. Clonar el repositorio

```bash
git clone https://github.com/marcosfermin/gated.git
cd gated
```

### 2. Configurar variables de entorno

```bash
cp .env.example .env
# Editar .env con tus credenciales de base de datos
```

### 3. Levantar los contenedores

```bash
docker compose up -d --build
```

La aplicacion estara disponible en `http://localhost:8080`.

### 4. Crear cuenta de administrador

Visita `http://localhost:8080/register.php` y crea tu cuenta de administrador.

### 5. Acceder al panel

Visita `http://localhost:8080/index.php` e inicia sesion con tu cuenta.

## Estructura del proyecto

```
gated/
├── config.php              # Configuracion de BD y uploads
├── index.php               # Panel de administracion (CRUD galerias)
├── watch.php               # Pagina publica del viewer
├── logs.php                # Dashboard de analytics
├── set_watched.php         # Endpoint para registrar vistas y watch time
├── upload_media.php        # Endpoint para subir archivos (AJAX)
├── delete_media.php        # Endpoint para eliminar archivos (AJAX)
├── login_form.php          # Formulario de login
├── logout.php              # Cerrar sesion
├── register.php            # Registro de administradores
├── installer.php           # Script de instalacion inicial
├── users.sql               # Schema de base de datos
├── Dockerfile              # Imagen PHP 8.2 + Apache
├── docker-compose.yml      # Orquestacion de servicios
├── docker-entrypoint.sh    # Script de inicio del contenedor
├── .env.example            # Variables de entorno de ejemplo
├── .dockerignore           # Archivos excluidos del build
└── uploads/                # Directorio de archivos subidos
    ├── gallery/            # Imagenes y videos de galerias
    └── thumbs/             # Thumbnails y meta images
```

## Schema de base de datos

### users
| Campo | Tipo | Descripcion |
|---|---|---|
| id | INT (PK) | ID del usuario |
| username | VARCHAR(50) | Nombre de usuario unico |
| password_hash | VARCHAR(255) | Hash de contrasena (bcrypt) |
| role | ENUM | admin, editor, viewer |
| created_at | TIMESTAMP | Fecha de creacion |

### galleries
| Campo | Tipo | Descripcion |
|---|---|---|
| id | INT (PK) | ID de la galeria |
| slug | VARCHAR(64) | Identificador unico para la URL |
| youtube_url | TEXT | URL del video de YouTube |
| title | VARCHAR(255) | Titulo de la galeria |
| description | TEXT | Descripcion para meta tags |
| meta_image | TEXT | Imagen de preview para redes sociales |
| created_at | TIMESTAMP | Fecha de creacion |

### gallery_media
| Campo | Tipo | Descripcion |
|---|---|---|
| id | INT (PK) | ID del archivo |
| gallery_id | INT (FK) | Referencia a la galeria |
| media_type | ENUM | image o video |
| file_path | TEXT | Ruta del archivo |
| original_filename | VARCHAR(255) | Nombre original del archivo |
| file_size | INT | Tamano en bytes |
| mime_type | VARCHAR(100) | Tipo MIME del archivo |
| created_at | TIMESTAMP | Fecha de subida |

### completions
| Campo | Tipo | Descripcion |
|---|---|---|
| id | INT (PK) | ID del registro |
| slug | VARCHAR(64) | Slug de la galeria vista |
| ip_address | VARCHAR(64) | IP del visitante (Cloudflare-aware) |
| user_agent | TEXT | User agent del navegador |
| watch_time | INT | Segundos de reproduccion del video |
| completed_at | DATETIME | Fecha y hora de la vista |

## Configuracion

### Limites de subida
- Tamano maximo por archivo: **100 MB**
- Tipos de imagen permitidos: JPG, JPEG, PNG, GIF, WebP
- Tipos de video permitidos: MP4, WebM, MOV

### Seguridad
- Autenticacion por sesion con timeout de 30 minutos
- Hashing de contrasenas con `PASSWORD_DEFAULT` (bcrypt)
- Prepared statements para prevencion de SQL injection
- Validacion de extensiones y MIME types en uploads
- Sanitizacion de output con `htmlspecialchars()`

### Variables de entorno

| Variable | Descripcion | Default |
|---|---|---|
| DB_HOST | Host de la base de datos | db |
| DB_NAME | Nombre de la base de datos | gated |
| DB_USER | Usuario de la base de datos | gated_user |
| DB_PASS | Contrasena de la base de datos | gated_password |

## Uso

### Crear una galeria

1. Accede al panel de administracion
2. Llena el formulario con el titulo, slug, URL de YouTube y descripcion
3. Sube las imagenes y videos arrastrando o seleccionando archivos
4. Opcionalmente configura una imagen de preview para redes sociales
5. Guarda la galeria

### Compartir un link

Comparte la URL con el formato:

```
https://tudominio.com/watch.php?slug=mi-galeria
```

### Ver estadisticas

Accede a `logs.php` desde el panel de administracion para ver:
- Estadisticas globales y por galeria
- Tiempo de reproduccion de cada visitante
- Graficos de tendencias
- Logs detallados con filtros
