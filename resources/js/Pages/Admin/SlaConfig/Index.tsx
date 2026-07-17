import React from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Checkbox } from '@/Components/ui/checkbox';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Components/ui/accordion';

const PRIORITIES = ['Kritis', 'Tinggi', 'Sedang', 'Rendah'] as const;
type Priority = typeof PRIORITIES[number];

const JENIS_LABELS: Record<string, string> = {
    respon: 'SLA Respon',
    penyelesaian: 'SLA Penyelesaian',
};

interface SlaConfigItem {
    sub_unit_id: number | null;
    priority: Priority;
    jenis: 'respon' | 'penyelesaian';
    threshold_minutes: number;
}

export default function SlaConfigIndex({ globalConfigs, subUnits }: any) {
    const { data, setData, put, processing, errors, transform } = useForm({
        configs: buildInitialConfigs(globalConfigs || [], subUnits || []),
    });

    const [overrides, setOverrides] = React.useState<Record<string, boolean>>(() => {
        const map: Record<string, boolean> = {};
        (subUnits || []).forEach((su: any) => {
            (su.sla_configs || []).forEach((sc: any) => {
                map[`${su.id}_${sc.priority}_${sc.jenis}`] = true;
            });
        });
        return map;
    });

    const getPriorityValue = (subUnitId: number | null, priority: Priority, jenis: string): number => {
        const item = data.configs.find(
            c => c.sub_unit_id === subUnitId && c.priority === priority && c.jenis === jenis
        );
        return item?.threshold_minutes ?? 0;
    };

    const setPriorityValue = (subUnitId: number | null, priority: Priority, jenis: string, value: number) => {
        setData('configs', data.configs.map(c =>
            c.sub_unit_id === subUnitId && c.priority === priority && c.jenis === jenis
                ? { ...c, threshold_minutes: value }
                : c
        ));
    };

    const toggleOverride = (subUnitId: number, priority: Priority, jenis: string, checked: boolean) => {
        const key = `${subUnitId}_${priority}_${jenis}`;
        setOverrides(prev => ({ ...prev, [key]: checked }));

        if (checked) {
            const globalVal = getPriorityValue(null, priority, jenis);
            setData('configs', data.configs.map(c =>
                c.sub_unit_id === subUnitId && c.priority === priority && c.jenis === jenis
                    ? { ...c, threshold_minutes: globalVal }
                    : c
            ));
        } else {
            setData('configs', data.configs.filter(c =>
                !(c.sub_unit_id === subUnitId && c.priority === priority && c.jenis === jenis)
            ));
        }
    };

    transform((data) => ({
        ...data,
        configs: data.configs.filter(c => 
            c.sub_unit_id === null || overrides[`${c.sub_unit_id}_${c.priority}_${c.jenis}`]
        )
    }));

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.sla-config.update'));
    };

    return (
        <AdminLayout title="Konfigurasi SLA">
            <Head title="Konfigurasi SLA" />

            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                    <div>
                        <h2 className="text-2xl font-bold">Konfigurasi SLA</h2>
                        <p className="text-sm text-slate-500">Atur threshold SLA respon dan penyelesaian berdasarkan Prioritas.</p>
                    </div>
                    <Button type="submit" disabled={processing}>Simpan Perubahan</Button>
                </div>
                {/* Global Default */}
                <Card>
                    <CardHeader><CardTitle className="text-base">Default Global</CardTitle></CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm min-w-[600px]">
                                <thead>
                                    <tr className="border-b">
                                        <th className="text-left py-2">Jenis</th>
                                        {PRIORITIES.map(p => <th key={p} className="text-center py-2">{p} (menit)</th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {['respon', 'penyelesaian'].map(jenis => (
                                        <tr key={jenis} className="border-b">
                                            <td className="py-2 font-medium">{JENIS_LABELS[jenis]}</td>
                                            {PRIORITIES.map(priority => (
                                                <td key={priority} className="py-2 text-center">
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        className="w-24 mx-auto text-center"
                                                        value={getPriorityValue(null, priority, jenis)}
                                                        onChange={e => setPriorityValue(null, priority, jenis, parseInt(e.target.value) || 0)}
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {errors.configs && <p className="text-sm text-destructive mt-2">{errors.configs}</p>}
                    </CardContent>
                </Card>

                {/* Per Sub Unit */}
                <Accordion type="multiple" className="space-y-2">
                    {(subUnits || []).map((su: any) => (
                        <AccordionItem key={su.id} value={`sub-${su.id}`}>
                            <AccordionTrigger className="text-base font-medium px-4 hover:no-underline">
                                {su.unit?.nama_unit} — {su.nama_layanan}
                            </AccordionTrigger>
                            <AccordionContent className="px-4 pb-4">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm min-w-[700px]">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="text-left py-2">Jenis</th>
                                                {PRIORITIES.map(p => (
                                                    <th key={p} className="text-center py-2">{p} (menit)</th>
                                                ))}
                                                <th className="text-center py-2 w-24">Override Semua</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {['respon', 'penyelesaian'].map((jenis, idx) => (
                                                <tr key={jenis} className="border-b">
                                                    <td className="py-2 font-medium">{JENIS_LABELS[jenis]}</td>
                                                    {PRIORITIES.map(priority => {
                                                        const isOverridden = overrides[`${su.id}_${priority}_${jenis}`] ?? false;
                                                        return (
                                                            <td key={priority} className="py-2 text-center">
                                                                <div className="flex items-center justify-center gap-2">
                                                                    <Checkbox
                                                                        checked={isOverridden}
                                                                        onCheckedChange={(checked) => toggleOverride(su.id, priority, jenis, !!checked)}
                                                                        title="Override prioritas ini"
                                                                    />
                                                                    {isOverridden ? (
                                                                        <Input
                                                                            type="number"
                                                                            min={1}
                                                                            className="w-20 text-center h-8"
                                                                            value={getPriorityValue(su.id, priority, jenis)}
                                                                            onChange={e => setPriorityValue(su.id, priority, jenis, parseInt(e.target.value) || 0)}
                                                                        />
                                                                    ) : (
                                                                        <span className="text-slate-400 w-20 inline-block">—</span>
                                                                    )}
                                                                </div>
                                                            </td>
                                                        );
                                                    })}
                                                    {idx === 0 && (
                                                        <td className="py-2 text-center" rowSpan={2}>
                                                            <Button 
                                                                type="button" 
                                                                variant="outline" 
                                                                size="sm"
                                                                onClick={() => {
                                                                    // Toggle all for this sub unit
                                                                    const anyChecked = PRIORITIES.some(p => overrides[`${su.id}_${p}_respon`] || overrides[`${su.id}_${p}_penyelesaian`]);
                                                                    PRIORITIES.forEach(p => {
                                                                        toggleOverride(su.id, p, 'respon', !anyChecked);
                                                                        toggleOverride(su.id, p, 'penyelesaian', !anyChecked);
                                                                    });
                                                                }}
                                                            >
                                                                Toggle All
                                                            </Button>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </AccordionContent>
                        </AccordionItem>
                    ))}
                </Accordion>

                <div className="flex justify-end">
                    <Button type="submit" disabled={processing}>Simpan Perubahan</Button>
                </div>
            </form>
        </AdminLayout>
    );
}

function buildInitialConfigs(globalConfigs: any[], subUnits: any[]): SlaConfigItem[] {
    const configs: SlaConfigItem[] = [];

    const pushIfMissing = (subUnitId: number | null, priority: Priority, jenis: string) => {
        const exists = configs.some(c => c.sub_unit_id === subUnitId && c.priority === priority && c.jenis === jenis);
        if (!exists) {
            configs.push({
                sub_unit_id: subUnitId,
                priority,
                jenis: jenis as 'respon' | 'penyelesaian',
                threshold_minutes: 60,
            });
        }
    };

    globalConfigs?.forEach((gc: any) => {
        pushIfMissing(null, gc.priority, gc.jenis);
        const idx = configs.findIndex(c => c.sub_unit_id === null && c.priority === gc.priority && c.jenis === gc.jenis);
        if (idx >= 0) configs[idx].threshold_minutes = gc.threshold_minutes;
    });

    [null, ...(subUnits || []).map((s: any) => s.id)].forEach(suId => {
        PRIORITIES.forEach(priority => {
            ['respon', 'penyelesaian'].forEach(jenis => {
                pushIfMissing(suId, priority, jenis);
            });
        });
    });

    subUnits?.forEach((su: any) => {
        (su.sla_configs || []).forEach((sc: any) => {
            const idx = configs.findIndex(c => c.sub_unit_id === su.id && c.priority === sc.priority && c.jenis === sc.jenis);
            if (idx >= 0) configs[idx].threshold_minutes = sc.threshold_minutes;
        });
    });

    return configs;
}
