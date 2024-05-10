const fs = require('fs')
const path = require('path')

// Function to rename the main bundle file
function renameFile(dir, targetFile, newFileName) {
  if (targetFile) {
    fs.renameSync(path.join(dir, targetFile), path.join(dir, newFileName))
  }
}

// Function to delete unwanted files
function deleteFiles(dir, filesToDelete) {
  filesToDelete.forEach((file) => {
    fs.unlinkSync(path.join(dir, file))
  })
}

// Function to clean up a directory
function cleanDirectory(dir, newFileName) {
  const files = fs.readdirSync(dir)

  // Find the main bundle file
  const targetFile = files.find(
    (file) =>
      file.startsWith('main.') &&
      !file.endsWith('.map') &&
      !file.endsWith('.LICENSE.txt')
  )

  // Rename the main bundle file
  renameFile(dir, targetFile, newFileName)

  // Filter out source map files, txt files, and other unwanted files
  const filesToDelete = files.filter(
    (file) =>
      file.endsWith('.map') ||
      file.endsWith('.txt') ||
      file === 'asset-manifest.json' ||
      file === 'index.html' ||
      file === 'manifest.json'
  )

  // Delete the unwanted files
  deleteFiles(dir, filesToDelete)
}

// Directories to process
const jsDir = path.resolve(__dirname, 'build', 'static', 'js')
const cssDir = path.resolve(__dirname, 'build', 'static', 'css')
const buildDir = path.resolve(__dirname, 'build')

// Rename and clean up JavaScript directory
cleanDirectory(jsDir, 'aichwp.js')

// Rename and clean up CSS directory (if necessary)
cleanDirectory(cssDir, 'aichwp.css')

// Delete unwanted files in the static directory
deleteFiles(buildDir, ['asset-manifest.json', 'index.html', 'manifest.json'])
