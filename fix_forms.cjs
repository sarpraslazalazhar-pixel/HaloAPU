const fs = require('fs');
const path = require('path');

const baseDir = path.join(__dirname, 'resources/js/Pages/Admin/MasterData');
const directories = ['Divisi', 'Jabatan', 'SubUnit', 'Unit', 'UnitOrganisasi'];

const inputWrapperRegex = /<div>(\s*<Label>)/g;
const select1Regex = /<select className="ml-2 border rounded"/g;
const select2Regex = /<select className="w-full border rounded p-2"/g;
const selectClass = '<select className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"';
const buttonSubmitRegex = /<Button type="submit">(Simpan|Update)<\/Button>/g;

directories.forEach(dir => {
    const filePath = path.join(baseDir, dir, 'Index.tsx');
    if (fs.existsSync(filePath)) {
        let content = fs.readFileSync(filePath, 'utf-8');
        
        // Fix input wrapper spacing
        content = content.replace(inputWrapperRegex, '<div className="space-y-2">$1');
        
        // Fix select styling
        content = content.replace(select1Regex, selectClass);
        content = content.replace(select2Regex, selectClass);
        
        // Fix button styling
        content = content.replace(buttonSubmitRegex, '<div className="flex justify-end pt-4"><Button type="submit">$1</Button></div>');
        
        fs.writeFileSync(filePath, content, 'utf-8');
        console.log(`Updated ${dir}/Index.tsx`);
    }
});
