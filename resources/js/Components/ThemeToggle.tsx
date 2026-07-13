import { Moon, Sun } from "lucide-react"
import { Button } from "@/Components/ui/button"
import { useTheme } from "@/Components/ThemeProvider"

export function ThemeToggle() {
  const { setTheme } = useTheme()

  const toggle = () => {
    const root = document.documentElement
    const isDark = root.classList.contains("dark")
    const next = isDark ? "light" : "dark"

    root.classList.remove("light", "dark")
    root.classList.add(next)
    localStorage.setItem("halo-apu-theme", next)
    setTheme(next)
  }

  return (
    <Button variant="ghost" size="icon" onClick={toggle}>
      <Sun className="h-[1.2rem] w-[1.2rem] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0" />
      <Moon className="absolute h-[1.2rem] w-[1.2rem] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100" />
      <span className="sr-only">Toggle theme</span>
    </Button>
  )
}
