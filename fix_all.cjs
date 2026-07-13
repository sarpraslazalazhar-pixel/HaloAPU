const fs = require('fs');
const path = require('path');

function walk(dir) {
    let results = [];
    const list = fs.readdirSync(dir);
    list.forEach(function(file) {
        file = path.join(dir, file);
        const stat = fs.statSync(file);
        if (stat && stat.isDirectory()) { 
            results = results.concat(walk(file));
        } else { 
            if (file.endsWith('.tsx') || file.endsWith('.ts')) {
                results.push(file);
            }
        }
    });
    return results;
}

const files = walk('resources/js');

files.forEach(file => {
    let content = fs.readFileSync(file, 'utf8');
    let original = content;

    // 1. Remove preserveState: true from ReloadOptions
    content = content.replace(/preserveState:\s*true,?\s*/g, '');
    content = content.replace(/router\.reload\(\{\s*\}\)/g, 'router.reload()');

    // 2. Fix app.tsx type errors
    if (file.endsWith('app.tsx')) {
        content = content.replace(/resolvePageComponent\(\s*`\.\/Pages\/\$\{name\}\.tsx`,\s*import\.meta\.glob\('\.\/Pages\/\*\*\/\*\.tsx'\)\s*\)/g, 
            "resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')) as any");
        content = content.replace(/setup\(\{ el, App, props \}\)/g, 'setup({ el, App, props }: any)');
    }

    // 3. Fix Layout types
    if (file.endsWith('AdminLayout.tsx') || file.endsWith('UserLayout.tsx')) {
        if (!content.includes('interface NavItem')) {
            content = content.replace(/const (userNavItems|navItems) = \[/, `interface NavItem {\n    label: string;\n    icon: any;\n    route: string;\n    routeName: string;\n    disabled?: boolean;\n    badge?: React.ReactNode;\n}\n\nconst $1: NavItem[] = [`);
        }
    }

    // 4. Fix Notifications/Index.tsx null string issue
    if (file.replace(/\\/g, '/').endsWith('Notifications/Index.tsx')) {
        content = content.replace(/format\(new Date\(item\.read_at\)/g, 'format(new Date(item.read_at!)');
    }

    // 5. Fix tooltip.tsx TooltipPopupState issue
    if (file.endsWith('tooltip.tsx')) {
        content = content.replace(
            /}: TooltipPrimitive\.Positioner\.Props\)/,
            '}: TooltipPrimitive.Popup.Props & { sideOffset?: number })'
        );
    }

    // 6. Fix toast.tsx and toaster.tsx
    if (file.endsWith('toast.tsx') && !file.endsWith('toaster.tsx')) {
        content = content.replace(/ToastPrimitive\.Props/g, 'ToastPrimitive.Root.Props');
        content = content.replace(/<ToastPrimitive\n/g, '<ToastPrimitive.Root\n');
        content = content.replace(/<ToastPrimitive /g, '<ToastPrimitive.Root ');
        content = content.replace(/<\/ToastPrimitive>/g, '</ToastPrimitive.Root>');
    }
    if (file.endsWith('toaster.tsx')) {
        content = content.replace(/import \{ Toaster as ToasterPrimitive \} from "@base-ui\/react\/toast"/, 'import { Toast as ToastPrimitive } from "@base-ui/react/toast"');
        content = content.replace(/ToasterPrimitive\.Props/g, 'ToastPrimitive.Provider.Props');
        content = content.replace(/<ToasterPrimitive\n/g, '<ToastPrimitive.Provider\n');
        content = content.replace(/<ToasterPrimitive /g, '<ToastPrimitive.Provider ');
        content = content.replace(/<\/ToasterPrimitive>/g, '</ToastPrimitive.Provider>');
    }

    if (original !== content) {
        fs.writeFileSync(file, content, 'utf8');
        console.log('Updated', file);
    }
});
