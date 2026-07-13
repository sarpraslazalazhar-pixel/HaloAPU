import React, { useRef, useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Camera, Loader2, User } from 'lucide-react';

interface ProfileModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: any;
    isAdmin?: boolean;
}

export default function ProfileModal({ open, onOpenChange, user, isAdmin = false }: ProfileModalProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const { data, setData, put, processing, errors, reset } = useForm({
        name: user?.name || user?.username || '',
        email: user?.email || '',
        no_wa: user?.no_wa || '',
        password: '',
        password_confirmation: '',
    });

    // Sync form data when user changes
    React.useEffect(() => {
        if (user) {
            setData({
                name: user.name || user.username || '',
                email: user.email || '',
                no_wa: user.no_wa || '',
                password: '',
                password_confirmation: '',
            });
        }
    }, [user, open]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const routeName = isAdmin ? 'admin.profil.update' : 'profil.update';
        put(route(routeName), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
            },
        });
    };

    const handleAvatarChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setUploading(true);
        const formData = new FormData();
        formData.append('avatar', file);
        const routeName = isAdmin ? 'admin.profil.upload-avatar' : 'profil.upload-avatar';
        router.post(route(routeName), formData, {
            preserveScroll: true,
            onFinish: () => setUploading(false),
        });
    };

    const avatarUrl = user?.avatar_path ? `/storage/${user.avatar_path}` : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="text-lg font-semibold">Edit Profil</DialogTitle>
                </DialogHeader>

                {/* Avatar */}
                <div className="flex flex-col items-center gap-3 py-2">
                    <div className="relative group">
                        <div className="w-20 h-20 rounded-full border-2 border-primary/20 overflow-hidden bg-muted flex items-center justify-center">
                            {avatarUrl ? (
                                <img src={avatarUrl} alt="Avatar" className="w-full h-full object-cover" />
                            ) : (
                                <User className="h-10 w-10 text-muted-foreground" />
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="absolute bottom-0 right-0 p-1.5 rounded-full bg-primary text-primary-foreground shadow-md hover:bg-primary/90 transition-colors"
                            disabled={uploading}
                        >
                            {uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Camera className="h-3.5 w-3.5" />}
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/png,image/jpg,image/jpeg"
                            className="hidden"
                            onChange={handleAvatarChange}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">Klik ikon kamera untuk ganti foto</p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4" autoComplete="off">
                    <div>
                        <Label htmlFor="profile_name">Nama Lengkap <span className="text-destructive">*</span></Label>
                        <Input
                            id="profile_name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Masukkan nama lengkap"
                        />
                        {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
                    </div>

                    <div>
                        <Label htmlFor="profile_email">Email <span className="text-destructive">*</span></Label>
                        <Input
                            id="profile_email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="email@contoh.com"
                        />
                        {errors.email && <p className="text-xs text-destructive mt-1">{errors.email}</p>}
                    </div>

                    <div>
                        <Label htmlFor="profile_no_wa">Nomor WhatsApp</Label>
                        <Input
                            id="profile_no_wa"
                            value={data.no_wa}
                            onChange={(e) => setData('no_wa', e.target.value)}
                            placeholder="08xxxxxxxxxx"
                        />
                        {errors.no_wa && <p className="text-xs text-destructive mt-1">{errors.no_wa}</p>}
                    </div>

                    <div className="border-t pt-4">
                        <p className="text-sm font-medium mb-3 text-muted-foreground">Ubah Password <span className="text-xs">(opsional)</span></p>
                        <div className="space-y-3">
                            <div>
                                <Label htmlFor="profile_password">Password Baru</Label>
                                <Input
                                    id="profile_password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Kosongkan jika tidak diubah"
                                    autoComplete="new-password"
                                />
                                {errors.password && <p className="text-xs text-destructive mt-1">{errors.password}</p>}
                            </div>
                            <div>
                                <Label htmlFor="profile_password_confirmation">Konfirmasi Password</Label>
                                <Input
                                    id="profile_password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    placeholder="Ulangi password baru"
                                    autoComplete="new-password"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Simpan
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
