import React, { useState, useRef } from 'react';
import { UploadCloud } from 'lucide-react';
import { cn } from '@/lib/utils';

interface FileDropzoneProps {
 onFilesSelected: (files: FileList | null) => void;
 accept?: string;
 multiple?: boolean;
 description?: string;
}

export function FileDropzone({ onFilesSelected, accept = '*', multiple = false, description }: FileDropzoneProps) {
 const [isDragging, setIsDragging] = useState(false);
 const fileInputRef = useRef<HTMLInputElement>(null);

 const handleDragOver = (e: React.DragEvent) => {
 e.preventDefault();
 setIsDragging(true);
 };

 const handleDragLeave = (e: React.DragEvent) => {
 e.preventDefault();
 setIsDragging(false);
 };

 const handleDrop = (e: React.DragEvent) => {
 e.preventDefault();
 setIsDragging(false);
 if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
 onFilesSelected(e.dataTransfer.files);
 }
 };

 const handleClick = () => {
 fileInputRef.current?.click();
 };

 return (
 <div
 className={cn(
 "relative flex flex-col items-center justify-center w-full p-6 border-2 border-dashed rounded-xl cursor-pointer transition-all duration-200 ease-in-out group mt-2",
 isDragging ? "border-blue-500 bg-blue-50/50 scale-[1.01]" : "border-slate-200 bg-slate-50/50 hover:bg-slate-100 hover:border-slate-300"
 )}
 onDragOver={handleDragOver}
 onDragLeave={handleDragLeave}
 onDrop={handleDrop}
 onClick={handleClick}
 >
 <input
 type="file"
 ref={fileInputRef}
 className="hidden"
 accept={accept}
 multiple={multiple}
 onChange={(e) => {
 onFilesSelected(e.target.files);
 e.target.value = '';
 }}
 />
 <div className={cn("p-3 rounded-full mb-3 transition-colors", isDragging ? "bg-blue-100 text-blue-600" : "bg-white text-slate-400 group-hover:text-blue-500 shadow-sm")}>
 <UploadCloud className="w-6 h-6" />
 </div>
 <p className="mb-1 text-sm text-slate-700">
 <span className="font-semibold text-blue-600 hover:underline">Klik untuk mengunggah</span> atau tarik & lepas
 </p>
 {description && (
 <p className="text-xs text-slate-500 text-center max-w-sm mt-1 leading-relaxed">{description}</p>
 )}
 </div>
 );
}
