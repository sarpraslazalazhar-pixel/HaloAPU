import React, { useState, useEffect } from 'react';
import mammoth from 'mammoth';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';
import { ZoomIn, ZoomOut, Maximize, FileText, Download, ExternalLink } from 'lucide-react';
import { Button } from '@/Components/ui/button';

interface AttachmentViewerProps {
 attachment: {
 id: number;
 original_name: string;
 file_path: string;
 };
 viewRoute: string;
 downloadRoute: string;
 children: React.ReactNode;
}

export function AttachmentViewer({ attachment, viewRoute, downloadRoute, children }: AttachmentViewerProps) {
 const [scale, setScale] = useState(1);
 
 const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(attachment.original_name);
 const viewUrl = route(viewRoute, attachment.id);
 const downloadUrl = route(downloadRoute, attachment.id);
 const isPdf = /\.pdf$/i.test(attachment.original_name);
 const isDocx = /\.docx$/i.test(attachment.original_name);

 const [docxHtml, setDocxHtml] = useState<string | null>(null);
 const [docxLoading, setDocxLoading] = useState(false);

 useEffect(() => {
 if (isDocx) {
 setDocxLoading(true);
 fetch(viewUrl)
 .then(res => res.arrayBuffer())
 .then(buffer => mammoth.convertToHtml({ arrayBuffer: buffer }))
 .then(result => {
 setDocxHtml(result.value);
 setDocxLoading(false);
 })
 .catch(err => {
 console.error('Error rendering docx', err);
 setDocxLoading(false);
 });
 }
 }, [isDocx, viewUrl]);

 const handleZoomIn = () => setScale(s => Math.min(s + 0.5, 5));
 const handleZoomOut = () => setScale(s => Math.max(s - 0.5, 0.5));
 const handleReset = () => setScale(1);

 return (
 <Dialog onOpenChange={(open) => { if (!open) setScale(1); }}>
 <DialogTrigger asChild>
 {children}
 </DialogTrigger>
 <DialogContent className="sm:max-w-4xl w-[95vw] h-[90vh] flex flex-col p-4 gap-3">
 <DialogHeader className="shrink-0 flex flex-row items-center justify-between pr-8">
 <DialogTitle className="truncate">{attachment.original_name}</DialogTitle>
 </DialogHeader>
 
 <div className="flex-1 min-h-0 relative bg-slate-100 rounded-md border flex flex-col overflow-hidden">
 {isImage ? (
 <>
 <div className="absolute top-3 right-3 z-10 flex gap-1 bg-white/90 p-1.5 rounded-lg shadow-sm border backdrop-blur-sm">
 <Button type="button" variant="ghost" size="icon" className="h-8 w-8 rounded-md" onClick={handleZoomOut} title="Zoom Out">
 <ZoomOut className="h-4 w-4" />
 </Button>
 <Button type="button" variant="ghost" size="icon" className="h-8 w-8 rounded-md" onClick={handleReset} title="Reset">
 <Maximize className="h-4 w-4" />
 </Button>
 <Button type="button" variant="ghost" size="icon" className="h-8 w-8 rounded-md" onClick={handleZoomIn} title="Zoom In">
 <ZoomIn className="h-4 w-4" />
 </Button>
 <a href={downloadUrl} target="_blank" rel="noopener noreferrer">
 <Button type="button" variant="ghost" size="icon" className="h-8 w-8 rounded-md" title="Download">
 <Download className="h-4 w-4" />
 </Button>
 </a>
 </div>
 <div className="flex-1 overflow-auto flex items-center justify-center p-4">
 <img 
 src={viewUrl} 
 alt={attachment.original_name} 
 style={{ 
 transform:`scale(${scale})`, 
 transition: 'transform 0.2s ease-out',
 transformOrigin: 'center'
 }} 
 className="max-w-full max-h-full object-contain shadow-sm bg-white"
 />
 </div>
 </>
 ) : isPdf ? (
 <div className="flex-1 w-full h-full">
 <iframe src={viewUrl} className="w-full h-full border-0" title={attachment.original_name} />
 </div>
 ) : isDocx ? (
 <div className="flex-1 w-full h-full bg-white overflow-auto p-8 prose max-w-none">
 {docxLoading ? (
 <div className="flex justify-center items-center h-full">
 <p className="text-slate-500">Memuat dokumen...</p>
 </div>
 ) : docxHtml ? (
 <div dangerouslySetInnerHTML={{ __html: docxHtml }} />
 ) : (
 <div className="flex justify-center items-center h-full text-center">
 <div>
 <p className="font-medium text-slate-700 text-lg">Gagal memuat pratinjau</p>
 <a href={downloadUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center justify-center gap-2 px-5 py-2.5 mt-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-sm text-sm font-medium">
 <Download className="w-4 h-4" /> Download File
 </a>
 </div>
 </div>
 )}
 </div>
 ) : (
 <div className="flex-1 flex flex-col items-center justify-center p-8 gap-4 bg-white">
 <div className="h-20 w-20 rounded-full bg-slate-100 flex items-center justify-center">
 <FileText className="w-10 h-10 text-slate-400" />
 </div>
 <div className="text-center">
 <p className="font-medium text-slate-700 text-lg">Preview tidak tersedia</p>
 <p className="text-sm text-slate-500 mb-6 mt-1">Format file ini tidak dapat ditampilkan secara langsung.</p>
 <a href={downloadUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-sm text-sm font-medium">
 <Download className="w-4 h-4" /> Download File
 </a>
 </div>
 </div>
 )}
 </div>
 </DialogContent>
 </Dialog>
 );
}
