# Redirect

Plataforma de links de redireccion con pagina de espera configurable. El administrador crea links con una URL de destino y define cuantos segundos se muestra la pagina de espera antes de redirigir al visitante.

## Como funciona

1. El administrador crea un link con una URL de destino (cualquier tipo: YouTube, Vimeo, video directo, sitio web, etc.)
2. Se genera un link unico (slug) para compartir
3. El visitante abre el link y ve una pagina de espera morada durante X segundos
4. Pasados los segundos configurados, el meta refresh redirige automaticamente a la URL de destino

## Caracteristicas

### Panel de Administracion
- Crear, editar y eliminar links de redireccion
- URL de destino de cualquier tipo (YouTube, Vimeo, .mp4, .webm, cualquier enlace)
- Configurar imagen de preview para redes sociales (OG tags)
- Configurar los segundos de espera antes del redirect (1 a 3600)
- Paginacion de links

### Pagina de Espera (watch.php)
- Pagina en blanco con fondo morado degradado
- Meta refresh automatico hacia la URL de destino
- Tiempo de espera configurable desde el panel de administracion
- Meta tags para compartir en redes sociales

### Analytics Dashboard
- **Estadisticas globales**: vistas totales, hoy, esta semana, este mes
- **Estadisticas por link**: vistas y visitantes unicos por cada slug
- **Visitantes unicos**: tracking por IP (compatible con Cloudflare)
- **Device breakdown**: porcentaje de mobile, desktop y tablet
- **Top links**: ranking de links mas visitados
- **Grafico de vistas**: chart de linea con los ultimos 7 dias
- **Actividad reciente**: feed en tiempo real de las ultimas vistas
- **Filtro por link**: dropdown para ver estadisticas de un slug especifico
- **Logs detallados**: tabla paginada con slug, IP, dispositivo y fecha

## Stack Tecnologico

| Componente | Tecnologia |
|---|---|
| Backend | PHP 8.2 |
| Base de datos | MySQL 8.0 |
| Servidor web | Apache |
| Frontend | HTML5, Bootstrap 5.3, Vanilla JS |
| Deploy | Docker & Docker Compose |

## Requisitos

- Docker y Docker Compose

## Instalacion

### 1. Clonar el repositorio

```bash
git clone https://github.com/marcosfermin/redirect.git
cd redirect
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
redirect/
├── config.php              # Configuracion de BD y helper de settings
├── index.php               # Panel de administracion (CRUD links + settings)
├── watch.php               # Pagina de espera con meta refresh
├── logs.php                # Dashboard de analytics
├── set_watched.php         # Endpoint para registrar vistas
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
└── uploads/                # Directorio de archivos subidos
    ├── gallery/            # Archivos multimedia
    └── thumbs/             # Meta images para redes sociales
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
| id | INT (PK) | ID del link |
| slug | VARCHAR(64) | Identificador unico para la URL |
| youtube_url | TEXT | URL de destino del redirect |
| title | VARCHAR(255) | Titulo del link |
| description | TEXT | Descripcion para meta tags |
| meta_image | TEXT | Imagen de preview para redes sociales |
| created_at | TIMESTAMP | Fecha de creacion |

### settings
| Campo | Tipo | Descripcion |
|---|---|---|
| setting_key | VARCHAR(100) (PK) | Clave del setting |
| setting_value | TEXT | Valor del setting |
| updated_at | TIMESTAMP | Ultima actualizacion |

**Settings disponibles:**

| Clave | Descripcion | Default |
|---|---|---|
| meta_refresh_seconds | Segundos de espera antes del redirect | 5 |

### completions
| Campo | Tipo | Descripcion |
|---|---|---|
| id | INT (PK) | ID del registro |
| slug | VARCHAR(64) | Slug del link visitado |
| ip_address | VARCHAR(64) | IP del visitante (Cloudflare-aware) |
| user_agent | TEXT | User agent del navegador |
| watch_time | INT | Segundos en la pagina de espera |
| completed_at | DATETIME | Fecha y hora de la visita |

## Configuracion

### Segundos de espera

Desde el panel de administracion, en la seccion **Settings**, configura cuantos segundos dura la pagina de espera antes de redirigir. Rango: 1 a 3600 segundos.

### Seguridad
- Autenticacion por sesion con timeout de 30 minutos
- Hashing de contrasenas con `PASSWORD_DEFAULT` (bcrypt)
- Prepared statements para prevencion de SQL injection
- Sanitizacion de output con `htmlspecialchars()`

### Variables de entorno

| Variable | Descripcion | Default |
|---|---|---|
| DB_HOST | Host de la base de datos | db |
| DB_NAME | Nombre de la base de datos | gated |
| DB_USER | Usuario de la base de datos | gated_user |
| DB_PASS | Contrasena de la base de datos | gated_password |

## Uso

### Crear un link de redireccion

1. Accede al panel de administracion en `/index.php`
2. Ingresa la URL de destino (cualquier tipo de enlace)
3. Opcionalmente agrega titulo, descripcion e imagen de preview
4. Guarda y copia el slug generado

### Compartir un link

```
https://tudominio.com/watch.php?slug=SLUG
```

### Configurar el tiempo de espera

En el panel de administracion, seccion **Settings**, define los segundos que el visitante vera la pagina morada antes de ser redirigido.

### Ver estadisticas

Accede a `logs.php` desde el panel para ver vistas, visitantes unicos, dispositivos y tendencias por link.
