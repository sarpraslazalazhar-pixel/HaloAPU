import React from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Checkbox } from '@/Components/ui/checkbox';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Components/ui/accordion';
import { ChevronDown } from 'lucide-react';

const TIER_LABELS: Record<number, string> = {
    1: 'Tier 1',
    2: 'Tier 2',
    3: 'Tier 3',
};

const JENIS_LABELS: Record<string, string> = {
    respon: 'SLA Respon',
    penyelesaian: 'SLA Penyelesaian',
};

interface SlaConfigItem {
    sub_unit_id: number | null;
    tier: number;
    jenis: 'respon' | 'penyelesaian';
    threshold_minutes: number;
}

export default function SlaConfigIndex({ globalConfigs, subUnits }: any) {
    const { data, setData, put, processing, errors } = useForm({
        configs: buildInitialConfigs(globalConfigs || [], subUnits || []),
    });

    const [overrides, setOverrides] = React.useState<Record<string, boolean>>(() => {
        const map: Record<string, boolean> = {};
        (subUnits || []).forEach((su: any) => {
            (su.sla_configs || []).forEach((sc: any) => {
                map[`${su.id}_${sc.tier}_${sc.jenis}`] = true;
            });
        });
        return map;
    });

    const getTierValue = (subUnitId: number | null, tier: number, jenis: string): number => {
        const item = data.configs.find(
            c => c.sub_unit_id === subUnitId && c.tier === tier && c.jenis === jenis
        );
        return item?.threshold_minutes ?? 0;
    };

    const setTierValue = (subUnitId: number | null, tier: number, jenis: string, value: number) => {
        setData('configs', data.configs.map(c =>
            c.sub_unit_id === subUnitId && c.tier === tier && c.jenis === jenis
                ? { ...c, threshold_minutes: value }
                : c
        ));
    };

    const toggleOverride = (subUnitId: number, tier: number, jenis: string, checked: boolean) => {
        const key = `${subUnitId}_${tier}_${jenis}`;
        setOverrides(prev => ({ ...prev, [key]: checked }));

        if (checked) {
            const globalVal = getTierValue(null, tier, jenis);
            setData('configs', data.configs.map(c =>
                c.sub_unit_id === subUnitId && c.tier === tier && c.jenis === jenis
                    ? { ...c, threshold_minutes: globalVal }
                    : c
            ));
        } else {
            setData('configs', data.configs.filter(c =>
                !(c.sub_unit_id === subUnitId && c.tier === tier && c.jenis === jenis)
            ));
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.sla-config.update'));
    };

    return (
        <AdminLayout title="Konfigurasi SLA">
            <Head title="Konfigurasi SLA" />

            <div className="mb-4">
                <h2 className="text-2xl font-bold">Konfigurasi SLA</h2>
                <p className="text-sm text-slate-500">Atur threshold SLA respon dan penyelesaian per layanan.</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
                {/* Global Default */}
                <Card>
                    <CardHeader><CardTitle className="text-base">Default Global</CardTitle></CardHeader>
                    <CardContent>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th className="text-left py-2">Jenis</th>
                                    {[1, 2, 3].map(t => <th key={t} className="text-center py-2">{TIER_LABELS[t]} (menit)</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {['respon', 'penyelesaian'].map(jenis => (
                                    <tr key={jenis} className="border-b">
                                        <td className="py-2 font-medium">{JENIS_LABELS[jenis]}</td>
                                        {[1, 2, 3].map(tier => (
                                            <td key={tier} className="py-2 text-center">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    className="w-24 mx-auto text-center"
                                                    value={getTierValue(null, tier, jenis)}
                                                    onChange={e => setTierValue(null, tier, jenis, parseInt(e.target.value) || 0)}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {errors.configs && <p className="text-sm text-destructive mt-2">{errors.configs}</p>}
                    </CardContent>
                </Card>

                {/* Per Sub Unit */}
                <Accordion type="multiple" className="space-y-2">
                    {(subUnits || []).map((su: any) => (
                        <AccordionItem key={su.id} value={`sub-${su.id}`}>
                            <AccordionTrigger className="text-base font-medium px-4">
                                {su.unit?.nama_unit} — {su.nama_layanan}
                            </AccordionTrigger>
                            <AccordionContent className="px-4 pb-4">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="text-left py-2">Jenis</th>
                                            {[1, 2, 3].map(t => (
                                                <th key={t} className="text-center py-2">{TIER_LABELS[t]} (menit)</th>
                                            ))}
                                            <th className="text-center py-2 w-24">Override</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {['respon', 'penyelesaian'].map(jenis => (
                                            <tr key={jenis} className="border-b">
                                                <td className="py-2 font-medium">{JENIS_LABELS[jenis]}</td>
                                                {[1, 2, 3].map(tier => {
                                                    const isOverridden = overrides[`${su.id}_${tier}_${jenis}`] ?? false;
                                                    return (
                                                        <td key={tier} className="py-2 text-center">
                                                            {isOverridden ? (
                                                                <Input
                                                                    type="number"
                                                                    min={1}
                                                                    className="w-24 mx-auto text-center"
                                                                    value={getTierValue(su.id, tier, jenis)}
                                                                    onChange={e => setTierValue(su.id, tier, jenis, parseInt(e.target.value) || 0)}
                                                                />
                                                            ) : (
                                                                <span className="text-slate-400">— (global)</span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="py-2 text-center">
                                                    <Checkbox
                                                        checked={overrides[`${su.id}_1_${jenis}`] ?? false}
                                                        onCheckedChange={(checked) => {
                                                            [1, 2, 3].forEach(t => {
                                                                toggleOverride(su.id, t, jenis, !!checked);
                                                            });
                                                        }}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
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

    const pushIfMissing = (subUnitId: number | null, tier: number, jenis: string) => {
        const exists = configs.some(c => c.sub_unit_id === subUnitId && c.tier === tier && c.jenis === jenis);
        if (!exists) {
            configs.push({
                sub_unit_id: subUnitId,
                tier,
                jenis: jenis as 'respon' | 'penyelesaian',
                threshold_minutes: 60,
            });
        }
    };

    globalConfigs?.forEach((gc: any) => {
        pushIfMissing(null, gc.tier, gc.jenis);
        const idx = configs.findIndex(c => c.sub_unit_id === null && c.tier === gc.tier && c.jenis === gc.jenis);
        if (idx >= 0) configs[idx].threshold_minutes = gc.threshold_minutes;
    });

    [null, ...(subUnits || []).map((s: any) => s.id)].forEach(suId => {
        [1, 2, 3].forEach(tier => {
            ['respon', 'penyelesaian'].forEach(jenis => {
                pushIfMissing(suId, tier, jenis);
            });
        });
    });

    subUnits?.forEach((su: any) => {
        (su.sla_configs || []).forEach((sc: any) => {
            const idx = configs.findIndex(c => c.sub_unit_id === su.id && c.tier === sc.tier && c.jenis === sc.jenis);
            if (idx >= 0) configs[idx].threshold_minutes = sc.threshold_minutes;
        });
    });

    return configs;
}
