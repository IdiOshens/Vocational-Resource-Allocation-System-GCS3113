import { API_BASE_URL } from "./config";

export async function fetchDepartments(token) {
  const url = `${API_BASE_URL}?action=departments`;

  const response = await fetch(url, {
    method: "GET",
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });

  return response.json();
}

export async function uploadResourceImage(token, resourceType, resourceId, imageUri) {
  const filename = imageUri.split("/").pop()?.split("?")[0] || "resource.jpg";
  const match = /\.(\w+)$/.exec(filename);
  const extension = match ? match[1].toLowerCase() : "jpg";
  const mimeType = extension === "png"
    ? "image/png"
    : extension === "gif"
      ? "image/gif"
      : extension === "webp"
        ? "image/webp"
        : "image/jpeg";
  const formData = new FormData();
  formData.append("image", {
    uri: imageUri,
    name: filename,
    type: mimeType,
  });
  formData.append("resource_type", resourceType);
  formData.append("resource_id", String(resourceId));

  const response = await fetch(`${API_BASE_URL}?action=upload-resource-image`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: formData,
  });
  return response.json();
}

export async function fetchCourses(token) {
  const url = `${API_BASE_URL}?action=courses`;

  const response = await fetch(url, {
    method: "GET",
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });

  return response.json();
}

export async function createClassroom(token, data) {
  const url = `${API_BASE_URL}?action=admin-create-classroom`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(data),
  });

  return response.json();
}

export async function updateClassroom(token, id, data) {
  const url = `${API_BASE_URL}?action=admin-update-classroom`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ ...data, classroom_id: id }),
  });

  return response.json();
}

export async function deleteClassroom(token, id) {
  const url = `${API_BASE_URL}?action=admin-delete-classroom`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ classroom_id: id }),
  });

  return response.json();
}

export async function createEquipment(token, data) {
  const url = `${API_BASE_URL}?action=admin-create-equipment`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(data),
  });

  return response.json();
}

export async function updateEquipment(token, id, data) {
  const url = `${API_BASE_URL}?action=admin-update-equipment`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ ...data, equipment_id: id }),
  });

  return response.json();
}

export async function deleteEquipment(token, id) {
  const url = `${API_BASE_URL}?action=admin-delete-equipment`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ equipment_id: id }),
  });

  return response.json();
}
