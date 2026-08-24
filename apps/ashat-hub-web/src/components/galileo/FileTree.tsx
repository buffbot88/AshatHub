import type { ReactNode } from 'react';

export type TreeNode = { name: string; path: string; children: TreeNode[] };

/**
 * Build a tree structure from a flat list of file paths.
 */
export function treeFromPaths(paths: string[]): TreeNode[] {
  const root: TreeNode[] = [];
  for (const path of paths) {
    const parts = path.split('/');
    const fileName = parts.pop() || path;
    let current = root;
    let currentPath = '';
    for (const directory of parts) {
      currentPath = currentPath ? `${currentPath}/${directory}` : directory;
      let node = current.find(
        (item) => item.name === directory && item.children.length > 0,
      );
      if (!node) {
        node = { name: directory, path: currentPath, children: [] };
        current.push(node);
      }
      current = node.children;
    }
    current.push({
      name: fileName,
      path: currentPath ? `${currentPath}/${fileName}` : fileName,
      children: [],
    });
  }
  return root;
}

export function FileTreeNodes({
  nodes,
  activeFile,
  onSelect,
}: {
  nodes: TreeNode[];
  activeFile: string;
  onSelect: (path: string) => void;
}): ReactNode {
  return nodes.map((node) =>
    node.children.length > 0 ? (
      <div key={node.path} className="g-file-tree-group">
        <span className="g-file-tree-dir">⌄ {node.name}</span>
        <FileTreeNodes
          nodes={node.children}
          activeFile={activeFile}
          onSelect={onSelect}
        />
      </div>
    ) : (
      <button
        key={node.path}
        type="button"
        className={`g-file-tree-file ${activeFile === node.path ? 'active' : ''}`}
        onClick={() => onSelect(node.path)}
      >
        {node.name}
      </button>
    ),
  );
}
