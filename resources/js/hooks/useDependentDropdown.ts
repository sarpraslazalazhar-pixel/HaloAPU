import { useState } from 'react';
import axios from 'axios';

export function useDependentDropdown(baseUrl: string) {
 const [options, setOptions] = useState<any[]>([]);
 const [loading, setLoading] = useState(false);

 const load = async (parentId: string | number) => {
 if (!parentId) {
 setOptions([]);
 return;
 }
 setLoading(true);
 try {
 const decodedUrl = decodeURIComponent(baseUrl);
 const url = decodedUrl.replace(/\{[^}]+\}/, String(parentId));
 const { data } = await axios.get(url);
 setOptions(data);
 } catch {
 setOptions([]);
 } finally {
 setLoading(false);
 }
 };

 return { options, loading, load };
}
