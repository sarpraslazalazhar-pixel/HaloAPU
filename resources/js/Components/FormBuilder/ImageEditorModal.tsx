import React, { useRef, useState, useEffect } from 'react';
import { ReactSketchCanvas, ReactSketchCanvasRef } from 'react-sketch-canvas';
import { X, Save, Eraser, PenTool, RotateCcw, RotateCw, Undo, Redo, Download } from 'lucide-react';

interface ImageEditorModalProps {
    isOpen: boolean;
    onClose: () => void;
    imageFile: File;
    onSave: (editedFile: File) => void;
}

export default function ImageEditorModal({ isOpen, onClose, imageFile, onSave }: ImageEditorModalProps) {
    const canvasRef = useRef<ReactSketchCanvasRef>(null);
    const [imageUrl, setImageUrl] = useState<string>('');
    const [strokeColor, setStrokeColor] = useState('#ef4444');
    const [strokeWidth, setStrokeWidth] = useState(4);
    const [isEraser, setIsEraser] = useState(false);
    const [dimensions, setDimensions] = useState({ width: 0, height: 0 });
    const [isProcessing, setIsProcessing] = useState(false);

    useEffect(() => {
        if (isOpen && imageFile) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const b64 = e.target?.result as string;
                if (b64) {
                    setImageUrl(b64);
                    const img = new Image();
                    img.onload = () => {
                        setDimensions({ width: img.width, height: img.height });
                    };
                    img.src = b64;
                }
            };
            reader.readAsDataURL(imageFile);
        } else {
            setImageUrl('');
            setDimensions({ width: 0, height: 0 });
        }
    }, [isOpen, imageFile]);

    if (!isOpen) return null;

    const handleSave = async () => {
        if (!canvasRef.current || isProcessing) return;
        setIsProcessing(true);
        try {
            const dataUrl = await canvasRef.current.exportImage('png');
            
            // Convert base64 to Blob
            const response = await fetch(dataUrl);
            const blob = await response.blob();
            
            // Create a new File object
            const editedFile = new File([blob], imageFile.name, {
                type: 'image/png',
                lastModified: Date.now(),
            });
            
            onSave(editedFile);
        } catch (error) {
            console.error('Failed to save edited image', error);
        } finally {
            setIsProcessing(false);
        }
    };

    const handleClear = () => {
        canvasRef.current?.clearCanvas();
    };

    const handleUndo = () => {
        canvasRef.current?.undo();
    };

    const handleRedo = () => {
        canvasRef.current?.redo();
    };

    // Calculate responsive sizing for the canvas wrapper
    let maxWidth = 800; // max modal width
    let maxHeight = window.innerHeight * 0.6; // 60vh max height
    
    let wrapperWidth = '100%';
    let wrapperHeight = '400px';

    if (dimensions.width > 0 && dimensions.height > 0) {
        const ratio = dimensions.width / dimensions.height;
        if (ratio > maxWidth / maxHeight) {
            // Width is the constraining factor
            wrapperWidth = '100%';
            wrapperHeight = `calc(100vw * (1/${ratio}))`;
        } else {
            // Height is the constraining factor
            wrapperHeight = '60vh';
            wrapperWidth = `calc(60vh * ${ratio})`;
        }
    }

    const colors = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#000000', '#ffffff'];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
            <div className="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                    <h3 className="text-lg font-semibold text-gray-800">Edit Foto & Coretan</h3>
                    <button 
                        onClick={onClose}
                        className="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full transition-colors"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Toolbar */}
                <div className="flex flex-wrap items-center gap-4 px-6 py-3 border-b border-gray-100 bg-white">
                    <div className="flex items-center bg-gray-100 p-1 rounded-lg">
                        <button
                            className={`p-2 rounded-md flex items-center gap-2 transition-all ${!isEraser ? 'bg-white shadow-sm text-blue-600' : 'text-gray-600 hover:text-gray-800'}`}
                            onClick={() => {
                                setIsEraser(false);
                                canvasRef.current?.eraseMode(false);
                            }}
                        >
                            <PenTool className="w-4 h-4" />
                            <span className="text-sm font-medium">Pena</span>
                        </button>
                        <button
                            className={`p-2 rounded-md flex items-center gap-2 transition-all ${isEraser ? 'bg-white shadow-sm text-blue-600' : 'text-gray-600 hover:text-gray-800'}`}
                            onClick={() => {
                                setIsEraser(true);
                                canvasRef.current?.eraseMode(true);
                            }}
                        >
                            <Eraser className="w-4 h-4" />
                            <span className="text-sm font-medium">Penghapus</span>
                        </button>
                    </div>

                    <div className="h-6 w-px bg-gray-200 hidden sm:block"></div>

                    {!isEraser && (
                        <div className="flex items-center gap-2">
                            {colors.map(c => (
                                <button
                                    key={c}
                                    className={`w-7 h-7 rounded-full border-2 transition-transform ${strokeColor === c ? 'scale-125 shadow-sm' : 'scale-100 border-transparent hover:scale-110'}`}
                                    style={{ 
                                        backgroundColor: c, 
                                        borderColor: strokeColor === c ? (c === '#ffffff' ? '#e5e7eb' : c) : (c === '#ffffff' ? '#e5e7eb' : 'transparent')
                                    }}
                                    onClick={() => setStrokeColor(c)}
                                />
                            ))}
                            <input 
                                type="color" 
                                value={strokeColor} 
                                onChange={(e) => setStrokeColor(e.target.value)}
                                className="w-7 h-7 p-0 border-0 rounded-full cursor-pointer overflow-hidden"
                            />
                        </div>
                    )}

                    <div className="h-6 w-px bg-gray-200 hidden sm:block"></div>

                    <div className="flex items-center gap-2">
                        <button onClick={handleUndo} className="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="Undo">
                            <Undo className="w-4 h-4" />
                        </button>
                        <button onClick={handleRedo} className="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="Redo">
                            <Redo className="w-4 h-4" />
                        </button>
                        <button onClick={handleClear} className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors text-sm font-medium ml-2">
                            Bersihkan
                        </button>
                    </div>
                </div>

                {/* Editor Area */}
                <div className="flex-1 bg-gray-100 overflow-auto flex items-center justify-center p-4 relative min-h-[400px]">
                    {dimensions.width > 0 ? (
                        <div 
                            className="relative shadow-lg ring-1 ring-black/5 bg-white mx-auto"
                            style={{ 
                                width: wrapperWidth, 
                                height: wrapperHeight,
                                maxWidth: '100%',
                                maxHeight: '60vh'
                            }}
                        >
                            <ReactSketchCanvas
                                ref={canvasRef}
                                strokeWidth={isEraser ? strokeWidth * 3 : strokeWidth}
                                strokeColor={strokeColor}
                                canvasColor="transparent"
                                backgroundImage={imageUrl}
                                preserveBackgroundImageAspectRatio="xMidYMid meet"
                                exportWithBackgroundImage={true}
                                style={{
                                    border: 'none',
                                    borderRadius: '0px'
                                }}
                            />
                        </div>
                    ) : (
                        <div className="flex items-center justify-center h-full w-full">
                            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t border-gray-100 bg-white flex justify-end gap-3">
                    <button 
                        onClick={onClose}
                        className="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors font-medium text-sm"
                    >
                        Batal
                    </button>
                    <button 
                        onClick={handleSave}
                        disabled={isProcessing}
                        className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm transition-colors font-medium text-sm flex items-center gap-2 disabled:opacity-70"
                    >
                        {isProcessing ? (
                            <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                        ) : (
                            <Save className="w-4 h-4" />
                        )}
                        Simpan Editan
                    </button>
                </div>
            </div>
        </div>
    );
}
