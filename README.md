# Halo APU V2 🚀

Halo APU is a modern web application built with the robust Laravel framework on the backend and React + Inertia.js on the frontend. It features a beautiful, responsive user interface styled with Tailwind CSS and Shadcn UI components.

## 🛠️ Technology Stack

- **Backend:** [Laravel](https://laravel.com/) (PHP Framework)
- **Frontend:** [React](https://react.dev/) + [Inertia.js](https://inertiajs.com/)
- **Styling:** [Tailwind CSS v4](https://tailwindcss.com/)
- **UI Components:** [Shadcn UI](https://ui.shadcn.com/)
- **Icons:** [Lucide React](https://lucide.dev/)
- **Build Tool:** [Vite](https://vitejs.dev/)

## 🚀 Getting Started

Follow these steps to set up the project locally.

### Prerequisites

Ensure you have the following installed on your machine:
- PHP >= 8.2
- Composer
- Node.js & npm
- MySQL / PostgreSQL (or your preferred database)

### Installation

1. **Clone the repository** (if not already cloned)
   ```bash
   git clone https://github.com/sarpraslazalazhar-pixel/HaloAPU.git
   cd HaloAPU
   ```

2. **Install PHP Dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js Dependencies**
   ```bash
   npm install
   ```

4. **Environment Setup**
   Copy the example environment file and configure your database settings:
   ```bash
   cp .env.example .env
   ```
   *Update the `.env` file with your database credentials.*

5. **Generate Application Key**
   ```bash
   php artisan key:generate
   ```

6. **Run Migrations** (Optional: with seeders)
   ```bash
   php artisan migrate --seed
   ```

7. **Start the Development Servers**

   You will need two terminal windows to run both the backend and frontend dev servers.

   Terminal 1 (Backend):
   ```bash
   php artisan serve
   ```

   Terminal 2 (Frontend):
   ```bash
   npm run dev
   ```

## 📁 Project Structure

- `app/` - Laravel backend logic (Controllers, Models, etc.)
- `resources/js/` - React frontend code (Pages, Components, Layouts)
- `routes/` - Application routing (web.php for Inertia routes)
- `database/` - Migrations and seeders

## 📝 License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
