const fs = require('fs');
const path = require('path');

function processDirectory(directory) {
    const files = fs.readdirSync(directory);
    
    for (const file of files) {
        const fullPath = path.join(directory, file);
        const stat = fs.statSync(fullPath);
        
        if (stat.isDirectory()) {
            processDirectory(fullPath);
        } else if (/\.(tsx|ts|jsx|js)$/.test(file)) {
            processFile(fullPath);
        }
    }
}

function processFile(filePath) {
    let content = fs.readFileSync(filePath, 'utf8');
    const originalContent = content;
    
    // Match dark: followed by anything that is not a space, quote, backtick, bracket (except inside arbitary values)
    const regex = /\bdark:[^\s'"`{}]+/g;
    content = content.replace(regex, '');
    
    // Clean up double spaces
    content = content.replace(/ {2,}/g, ' ');
    
    // Clean up space near quotes in className
    content = content.replace(/="/g, '="');
    content = content.replace(/ \`/g, '`');
    content = content.replace(/\` /g, '`');
    
    if (content !== originalContent) {
        fs.writeFileSync(filePath, content, 'utf8');
        console.log(`Updated: ${filePath}`);
    }
}

const targetDir = path.join(__dirname, 'resources', 'js');
processDirectory(targetDir);
console.log('Done!');
