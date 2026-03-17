import axios from "axios";

const api = axios.create({
  baseURL: "/api",
  headers: { "Content-Type": "application/json" },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);

export default api;

export async function fetchList<T = unknown>(url: string, params?: Record<string, string>): Promise<T> {
  const { data } = await api.get(url, { params });
  return data;
}

export async function fetchOne<T = unknown>(url: string): Promise<T> {
  const { data } = await api.get(url);
  return data;
}

export async function createOne<T = unknown>(url: string, body: unknown): Promise<T> {
  const { data } = await api.post(url, body);
  return data;
}

export async function updateOne<T = unknown>(url: string, body: unknown): Promise<T> {
  const { data } = await api.patch(url, body);
  return data;
}

export async function deleteOne<T = unknown>(url: string): Promise<T> {
  const { data } = await api.delete(url);
  return data;
}

export async function uploadInvoice(file: File, companyId: string): Promise<unknown> {
  const formData = new FormData();
  formData.append("file", file);
  formData.append("companyId", companyId);
  const { data } = await api.post("/invoices", formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}
