export function groupResourcesByDepartment(resources) {
  if (!resources.length) {
    return [];
  }

  const sections = new Map();
  for (const resource of resources) {
    const departmentId = resource.department_id ?? resource.responsible_department_id;
    const isShared = departmentId == null;
    const key = isShared ? "shared" : String(departmentId);
    let section = sections.get(key);
    if (!section) {
      section = {
        key,
        title: isShared
          ? "Shared / No Department"
          : resource.department_name || `Department ${departmentId}`,
        data: [],
      };
      sections.set(key, section);
    }
    section.data.push(resource);
  }

  if (!sections.has("shared")) {
    sections.set("shared", {
      key: "shared",
      title: "Shared / No Department",
      data: [],
    });
  }

  const departmentSections = [...sections.values()]
    .filter((section) => section.key !== "shared")
    .sort((a, b) => a.title.localeCompare(b.title));
  return [...departmentSections, sections.get("shared")];
}
