# 🏎️ Assetto Corsa Telemetry

<p align="left">
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue">
  <img src="https://img.shields.io/badge/Tailwind-CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white" alt="Tailwind">
  <img src="https://img.shields.io/badge/uPlot-Charts-blue?style=for-the-badge" alt="uPlot">
</p>

**Assetto Corsa Telemetry** es una plataforma de análisis de datos en tiempo real diseñada para Assetto Corsa. Permite a los pilotos visualizar su telemetría de forma profesional, comparar vueltas y detectar errores en la trazada mediante un mapa dinámico sincronizado.

---

## 🔥 Características Principales

* **📊 Gráficas de Alto Rendimiento:** Visualización ultra fluida de Velocidad, RPM, Acelerador y Freno mediante `uPlot` (Canvas).
* **🗺️ Trackmap Dinámico:** Generación automática del trazado del circuito a partir de las coordenadas GPS (X, Z) del coche.
* **🧹 Algoritmo Anti-Glitch:** Filtro inteligente que elimina saltos de telemetría y "picos" de datos corruptos en la línea de meta.
* **🔍 Zoom Sincronizado:** Al ampliar una zona de la gráfica para analizar una frenada, el sector correspondiente se resalta automáticamente en el mapa.
* **🏁 Comparativa de Vueltas:** Superposición de dos vueltas distintas para analizar diferencias de velocidad y puntos de frenada.
* **📂 Procesamiento Local:** Lectura instantánea de archivos CSV masivos en el navegador usando `PapaParse`.

---

## 🛠️ Stack Tecnológico

| Componente | Tecnología |
| :--- | :--- |
| **Backend** | Laravel 11 (PHP 8.2+) |
| **Frontend** | Vue 3 (Composition API) |
| **Comunicación** | Inertia.js |
| **Gráficos** | uPlot (Motor Canvas de alto rendimiento) |
| **Base de Datos** | SQLite (Soporta MySQL/PostgreSQL) |
| **Estilos** | Tailwind CSS (Dark Mode) |

---

## 🚀 Instalación y Configuración

Para ejecutar este proyecto en tu entorno local (recomendado usar **Laravel Herd**):

1. **Clonar el repositorio:**
   ```bash
   git clone [https://github.com/ngarciia04/ac-telemetry.git](https://github.com/ngarciia04/ac-telemetry.git)
   cd ac-telemetry

2. **Instalar dependencias de PHP:**
   composer install
   ```bash
   composer install

3. **Instalar dependencias de JS:**
   ```bash
   npm install
   
4. **Preparar el entorno:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   
5. **Lanzar aplicacion: **
   ```bash
   npm run dev

<div align="center">
<p><b>Nacho García</b></p>
<a href="https://github.com/ngarciia04">Visitar mi Perfil</a>
</div>
   
